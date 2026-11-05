<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\ReconciliationException;
use App\Models\SourcingLot;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Open items that need documents, kept visible with an audit trail. Registering them only records that they exist; it never creates a payment, expense, refund or balancing
 * entry, and a resolution needs a written note from a person other than the one who opened it. An unresolved exception stays open however long it takes.
 */
class ReconciliationExceptions
{
    /**
     * Voided payments (from the audit log) and the receipt that most likely replaced each: the same amount first, otherwise the nearest unmatched receipt dated that day.
     * The pairing is a lead for a person to check, never a conclusion.
     *
     * @return Collection<int, array{void_payment: int, amount: int, paid_on: string, replacement: ?Payment, difference: ?int}>
     */
    public static function voidedPayments(): Collection
    {
        $voids = AuditLog::where('action', 'payment.voided')->orderBy('id')->get()->map(function ($log) {
            preg_match('/payment (\d+)/', (string) $log->subject, $id);
            preg_match('/amount (\d+) paid (\d{4}-\d{2}-\d{2})/', (string) $log->details, $d);

            return ['void_payment' => (int) ($id[1] ?? 0), 'amount' => (int) ($d[1] ?? 0), 'paid_on' => $d[2] ?? '', 'at' => $log->created_at];
        })->filter(fn ($v) => $v['void_payment'] > 0 && $v['paid_on'] !== '')->values();
        $payments = Payment::orderBy('id')->get();
        $taken = [];
        $pair = [];
        foreach ($voids as $i => $v) { // exact amount first
            $match = $payments->first(fn ($p) => ! in_array($p->id, $taken, true) && $p->id > $v['void_payment'] && (int) $p->amount === $v['amount']);
            if ($match) {
                $taken[] = $match->id;
                $pair[$i] = $match;
            }
        }
        foreach ($voids as $i => $v) { // then the nearest receipt on the same day
            if (isset($pair[$i])) {
                continue;
            }
            $match = $payments->filter(fn ($p) => ! in_array($p->id, $taken, true) && $p->paid_on->toDateString() === $v['paid_on'])->sortBy(fn ($p) => abs((int) $p->amount - $v['amount']))->first();
            if ($match) {
                $taken[] = $match->id;
                $pair[$i] = $match;
            }
        }

        return $voids->map(fn ($v, $i) => $v + ['replacement' => $pair[$i] ?? null, 'difference' => isset($pair[$i]) ? (int) $pair[$i]->amount - $v['amount'] : null]);
    }

    /** Register the open items that genuinely need evidence. Safe to repeat. @return int how many new items were registered */
    public static function register(User $user): int
    {
        $new = 0;
        $open = function (string $key, string $type, string $subject, ?int $amount, string $detail, string $evidence) use ($user, &$new) {
            $row = ReconciliationException::firstOrCreate(['key' => $key], ['type' => $type, 'subject' => $subject, 'amount' => $amount, 'detail' => $detail, 'evidence_required' => $evidence, 'opened_by' => $user->id]);
            $new += $row->wasRecentlyCreated ? 1 : 0;
            if ($row->wasRecentlyCreated) {
                Audit::log('reconciliation.exception_opened', $key, $subject);
            }
        };
        foreach (self::voidedPayments() as $v) {
            if ($v['difference'] === 0) {
                continue;
            }
            $rep = $v['replacement'];
            $open('void:payment:'.$v['void_payment'], 'voided_payment', 'Voided payment #'.$v['void_payment'].' ('.number_format($v['amount'] / 100, 2).')', $v['difference'],
                'Payment #'.$v['void_payment'].' of '.number_format($v['amount'] / 100, 2).' dated '.$v['paid_on'].' was voided. '.($rep ? 'Probable replacement: payment #'.$rep->id.' of '.number_format($rep->amount / 100, 2).' (difference '.number_format($v['difference'] / 100, 2).').' : 'No replacement receipt was found.'),
                'The customer, bank or bKash record showing the amount actually received on that day, and the reason the first entry was voided. No payment, expense or refund is created without it.');
        }
        $unassigned = AccountingService::balance(null);
        if ($unassigned !== 0) {
            $open('cash:unassigned', 'historical_cash', 'Cash not assigned to a financial account', $unassigned,
                'The ledger holds '.number_format($unassigned / 100, 2).' of cash with no financial account. Receipts confirmed by the owner are not in the ledger yet (they are journaled at period close or by real-time posting).',
                'Statements or records of the bKash, bank and cash-in-hand accounts that actually held the money, then the existing opening cash allocation with a Board approval. No balancing entry is made.');
        }
        foreach (SourcingLot::whereNull('ownership')->where('status', '!=', 'cancelled')->get() as $lot) {
            $open('lot:ownership:'.$lot->id, 'lot_ownership', 'Ownership of lot '.$lot->number.' is unknown', null,
                'Lot '.$lot->number.' ('.$lot->title.') has no recorded owner, so handover, valuation, supplier returns and client settlement are blocked for it.',
                'The signed order or agreement showing whose goods these are, the supplier purchase order or invoice, and proof of who paid for them.');
        }

        return $new;
    }

    public static function resolve(ReconciliationException $exception, string $note, User $user): void
    {
        if ($exception->status !== 'open') {
            throw new RuntimeException('This item is already resolved.');
        }
        if (mb_strlen(trim($note)) < 15) {
            throw new RuntimeException('Write what evidence resolved it (at least a sentence).');
        }
        if ($exception->opened_by === $user->id) {
            throw new RuntimeException('Someone other than the person who opened the item must resolve it.');
        }
        $exception->update(['status' => 'resolved', 'resolution_note' => trim($note), 'resolved_by' => $user->id, 'resolved_at' => now()]);
        Audit::log('reconciliation.exception_resolved', $exception->key);
    }
}
