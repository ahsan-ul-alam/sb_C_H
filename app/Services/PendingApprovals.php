<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\CostRecognition;
use App\Models\Expense;
use App\Models\LoanReceivable;
use App\Models\User;
use App\Support\Money;

/**
 * Everything waiting for a second person, in one list, grouped the way office staff think about it. Read-only: each row carries the route of
 * the existing decision action (which enforces the permission and the "never your own" rule), so nothing is approved by this class.
 *
 * @phpstan-type Item array{group: string, title: string, detail: string, amount: int, by: ?int, by_name: ?string, date: ?string, route: string, param: mixed, permission: string, link: ?string}
 */
class PendingApprovals
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $items = [];
        foreach (Expense::with('creator')->where('status', 'pending')->orderBy('id')->get() as $e) {
            $group = match (true) {
                $e->classification === 'supplier_advance' && ! $e->reverses_id => 'Supplier advances',
                (bool) $e->reverses_id && in_array($e->correction_type, ['refund_received', 'credit_note'], true) => 'Supplier refunds & credits',
                (bool) $e->reverses_id => 'Financial corrections',
                default => 'Expenses & payments',
            };
            $items[] = self::item($group, $e->category.': '.$e->description, $e->classification === 'acquisition' ? 'Acquisition cost (capitalised)' : ucfirst((string) $e->classification), (int) $e->amount,
                $e->created_by, $e->creator?->name, $e->spent_on?->toDateString(), 'finance.expenses.decide', $e, 'expense.verify', route('finance.expenses'));
        }
        foreach (AdvanceSettlement::with(['lot', 'supplier', 'creator'])->where('status', 'pending')->orderBy('id')->get() as $s) {
            $items[] = self::item('Arrival & settlement', 'Lot '.$s->lot->number.' — '.$s->supplier->name, $s->kind === 'payable' ? 'Accepted goods not yet paid for (payable)' : 'Advance settled against accepted goods',
                (int) $s->amount, $s->created_by, $s->creator?->name, $s->settled_on->toDateString(), 'sourcing.advances.decide', $s, 'sourcing.advance.approve', route('sourcing.advances'));
        }
        foreach (CostRecognition::with(['lot', 'creator'])->where('status', 'pending')->orderBy('id')->get() as $c) {
            $items[] = self::item('Cost finalisation', 'Lot '.$c->lot->number, 'Release product cost into profit', (int) $c->amount, $c->created_by, $c->creator?->name,
                $c->recognised_on->toDateString(), 'sourcing.cost-recognitions.decide', $c, 'sourcing.advance.approve', route('sourcing.advances'));
        }
        foreach (LoanReceivable::with('creator')->where('status', 'pending')->orderBy('id')->get() as $l) {
            $items[] = self::item('Financial corrections', 'Expense #'.$l->expense_id.' reclassified as a loan to '.$l->borrower_name, 'Loan receivable', (int) $l->principal, $l->created_by,
                $l->creator?->name, $l->lent_on->toDateString(), 'finance.loans-receivable.decide', $l, 'loan.verify', route('finance.loans-receivable'));
        }

        return $items;
    }

    public static function count(): int
    {
        return Expense::where('status', 'pending')->count() + AdvanceSettlement::where('status', 'pending')->count()
            + CostRecognition::where('status', 'pending')->count() + LoanReceivable::where('status', 'pending')->count();
    }

    /** @return array<string, mixed> */
    private static function item(string $group, string $title, string $detail, int $amount, ?int $by, ?string $byName, ?string $date, string $route, mixed $param, string $permission, ?string $link): array
    {
        return ['group' => $group, 'title' => $title, 'detail' => $detail, 'amount' => $amount, 'amount_text' => Money::format($amount), 'by' => $by, 'by_name' => $byName,
            'date' => $date, 'route' => $route, 'param' => $param, 'permission' => $permission, 'link' => $link];
    }

    /** Whether this user may decide the item: holds the permission and did not create it. */
    public static function canDecide(User $user, array $item): bool
    {
        return $user->can($item['permission']) && $item['by'] !== $user->id;
    }
}
