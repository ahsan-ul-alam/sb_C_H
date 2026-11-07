<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientExpense;
use App\Models\ClientExpenseEvent;
use App\Models\ClientOrder;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Client-borne expenses for client profit reporting. ATTRIBUTION ONLY: nothing here posts to the ledger, so a client expense is never a Sellbees
 * expense or liability. Entry -> Finance review (a different person) -> approval (a third person, Board) -> optional reversal with a reason.
 * Allocation rows (project, optionally order and product) must add up to the amount. A duplicate is refused by a key over the identifying fields,
 * and an equivalent ERP expense can be linked (once) instead of entering the cost again.
 */
class ClientExpenses
{
    /**
     * @param  array{category: string, amount: int, spent_on: string, responsible_party: string, paid_status: string, reference: string, description?: ?string, allocation_basis?: ?string, allocation_note?: ?string, expense_id?: ?int, allocations: list<array{project_id: int, client_order_id?: ?int, supplier_product_id?: ?int, amount: int}>}  $data
     */
    public static function record(Client $client, array $data, User $user): ClientExpense
    {
        if (! array_key_exists($data['category'], ClientExpense::CATEGORIES)) {
            throw new RuntimeException('Choose one of the expense categories.');
        }
        if ($data['amount'] <= 0) {
            throw new RuntimeException('The amount must be greater than zero.');
        }
        if (! in_array($data['responsible_party'], ['client', 'sellbees'], true) || ! in_array($data['paid_status'], ['paid', 'unpaid'], true) || trim($data['reference']) === '') {
            throw new RuntimeException('Give the responsible party, paid status and a supporting reference.');
        }
        $allocations = $data['allocations'];
        if ($allocations === [] || (int) array_sum(array_column($allocations, 'amount')) !== $data['amount'] || collect($allocations)->contains(fn ($a) => $a['amount'] <= 0)) {
            throw new RuntimeException('The allocation lines must be positive and add up exactly to the expense amount.');
        }
        if (count($allocations) > 1 && trim((string) ($data['allocation_note'] ?? '')) === '') {
            throw new RuntimeException('A shared expense needs the basis on which it was split.');
        }
        foreach ($allocations as $a) {
            $project = Project::find($a['project_id']);
            if ($project === null || $project->client_id !== $client->id) {
                throw new RuntimeException('Every allocation must be to a project of this client.');
            }
            if (($a['client_order_id'] ?? null) && ! ClientOrder::whereKey($a['client_order_id'])->where('project_id', $project->id)->exists()) {
                throw new RuntimeException('An allocated order must belong to the allocated project.');
            }
        }
        $treatment = $data['charge_treatment'] ?? 'additional';
        if (! in_array($treatment, ['additional', 'included_in_acquisition'], true)) {
            throw new RuntimeException('Say whether this is an additional charge or a fee already included in the acquisition cost.');
        }
        $sourceInvoice = ! empty($data['source_invoice_id']) ? Invoice::with('project')->find($data['source_invoice_id']) : null;
        if (! empty($data['source_invoice_id']) && ($sourceInvoice === null || $sourceInvoice->project->client_id !== $client->id)) {
            throw new RuntimeException('The source invoice must be an invoice of this client.');
        }
        if ($treatment === 'included_in_acquisition' && $sourceInvoice === null) {
            throw new RuntimeException('A fee included in the acquisition cost must be linked to the invoice that includes it. It is kept for information and is not deducted again.');
        }
        if (! empty($data['expense_id'])) {
            $existing = Expense::find($data['expense_id']);
            if ($existing === null || $existing->amount < $data['amount']) {
                throw new RuntimeException('The linked ERP expense does not exist or is smaller than this amount.');
            }
        }

        $key = hash('sha256', implode('|', [$client->id, $data['category'], $data['amount'], $data['spent_on'], mb_strtolower(trim($data['reference']))]));

        try {
            return DB::transaction(function () use ($client, $data, $user, $key, $allocations, $treatment, $sourceInvoice) {
                $expense = ClientExpense::create([
                    'client_id' => $client->id, 'category' => $data['category'], 'amount' => $data['amount'], 'spent_on' => $data['spent_on'], 'responsible_party' => $data['responsible_party'],
                    'paid_status' => $data['paid_status'], 'reference' => trim($data['reference']), 'description' => $data['description'] ?? null,
                    'allocation_basis' => $data['allocation_basis'] ?? 'direct', 'allocation_note' => $data['allocation_note'] ?? null, 'expense_id' => $data['expense_id'] ?? null,
                    'charge_treatment' => $treatment, 'source_invoice_id' => $sourceInvoice?->id, 'created_by' => $user->id, 'duplicate_key' => $key,
                ]);
                foreach ($allocations as $a) {
                    $expense->allocations()->create(['project_id' => $a['project_id'], 'client_order_id' => $a['client_order_id'] ?? null, 'supplier_product_id' => $a['supplier_product_id'] ?? null, 'amount' => $a['amount']]);
                }
                self::event($expense, 'entered', $user);

                return $expense;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('This client expense (or the linked ERP expense) is already recorded.');
        }
    }

    public static function review(ClientExpense $expense, User $user): void
    {
        self::locked($expense, function (ClientExpense $e) use ($user) {
            if ($e->status !== 'entered') {
                throw new RuntimeException('Only an entered expense can be reviewed.');
            }
            if ($e->created_by === $user->id) {
                throw new RuntimeException('The person who entered an expense cannot review it.');
            }
            $e->update(['status' => 'reviewed', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
            self::event($e, 'reviewed', $user);
        });
    }

    public static function decide(ClientExpense $expense, bool $approve, ?string $note, User $user): void
    {
        self::locked($expense, function (ClientExpense $e) use ($approve, $note, $user) {
            if ($e->status !== 'reviewed') {
                throw new RuntimeException('Only a reviewed expense can be approved or rejected.');
            }
            if (in_array($user->id, [$e->created_by, $e->reviewed_by], true)) {
                throw new RuntimeException('Approval must come from someone other than the person who entered and the person who reviewed the expense.');
            }
            if (! $approve && trim((string) $note) === '') {
                throw new RuntimeException('Give a reason for rejecting.');
            }
            $e->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => $note]);
            self::event($e, $approve ? 'approved' : 'rejected', $user, $note);
        });
    }

    /** A wrong expense is reversed (with a reason) and, if needed, entered again correctly; nothing is edited or deleted. */
    public static function reverse(ClientExpense $expense, string $reason, User $user): void
    {
        self::locked($expense, function (ClientExpense $e) use ($reason, $user) {
            if (in_array($e->status, ['reversed', 'rejected'], true)) {
                throw new RuntimeException('This expense is already closed.');
            }
            if (trim($reason) === '') {
                throw new RuntimeException('Give a reason for the reversal.');
            }
            $e->update(['status' => 'reversed', 'reversed_by' => $user->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'expense_id' => null,
                'duplicate_key' => hash('sha256', $e->duplicate_key.'|reversed|'.$e->id)]);   // frees the key so the correct entry can be recorded
            self::event($e, 'reversed', $user, $reason);
        });
    }

    private static function locked(ClientExpense $expense, \Closure $work): void
    {
        DB::transaction(fn () => $work(ClientExpense::whereKey($expense->id)->lockForUpdate()->firstOrFail()));
    }

    private static function event(ClientExpense $expense, string $event, User $user, ?string $note = null): void
    {
        ClientExpenseEvent::create(['client_expense_id' => $expense->id, 'event' => $event, 'user_id' => $user->id, 'note' => $note]);
        Audit::log('client_expense.'.$event, 'client expense '.$expense->id, $expense->client->sb_number.' '.$expense->category.' '.$expense->amount);
    }
}
