<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! function_exists('pfLoadBackup')) {
    /**
     * TEMPORARY (removed with the production-fix command). The rows of the 2026-11-08 production backup that the one-time repair is about, under their original
     * ids and amounts (minor units): clients and SB numbers, Suchona Mart / Rivo Mart / SK Mart projects, invoices, payments, allocations, the two loans receivable,
     * and the cash movements of the 24 journal entries.
     */
    function pfLoadBackup(): void
    {
        $now = now();
        $user = (int) DB::table('users')->value('id');
        foreach ([1 => 'Minuddin', 2 => 'Shariful Islam', 3 => 'SK Mart'] as $id => $name) {
            DB::table('clients')->insert(['id' => $id, 'name' => $name, 'mobile' => '0170000000'.$id, 'sb_number' => 'SB-00000'.$id, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('projects')->insert(['id' => $id, 'client_id' => $id, 'name' => ['Rivo Mart', 'Suchona Mart', 'Sk Mart'][$id - 1], 'status' => 'active', 'currency' => 'BDT', 'started_on' => '2026-10-30', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('sb_number_sequence')->where('id', 1)->update(['last_value' => 3]);
        foreach ([[1, 1, 'Onboarding', 1000000], [2, 1, 'Product purchasing', 735000], [3, 1, 'Marketing Charges', 265000], [4, 2, 'Onboarding', 1000000], [5, 2, 'Product Sourcing', 1920000], [6, 3, 'Oboarding', 1000000], [7, 3, 'Product Sourcing', 735000]] as [$id, $p, $n, $a]) {
            DB::table('segments')->insert(['id' => $id, 'project_id' => $p, 'name' => $n, 'amount' => $a, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([[1, 1, 'INV-1', 735000], [2, 1, 'INV-2', 1000000], [3, 1, 'INV-3', 265000], [4, 2, 'INV-OB261', 1000000], [6, 2, 'INV-PR261', 1920000], [7, 3, 'INV-7', 735000], [9, 3, 'INV-9', 265000], [10, 2, 'INV-PR2-L2', 2904000]] as [$id, $p, $n, $t]) {
            DB::table('invoices')->insert(['id' => $id, 'project_id' => $p, 'number' => $n, 'issued_on' => '2026-10-30', 'currency' => 'BDT', 'total' => $t, 'amount_paid' => $t, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('invoice_items')->insert([
            ['invoice_id' => 4, 'segment_id' => 4, 'description' => 'Onboarding', 'quantity' => 1, 'unit_price' => 1000000, 'amount' => 1000000],
            ['invoice_id' => 6, 'segment_id' => null, 'description' => 'Goods', 'quantity' => 1, 'unit_price' => 1920000, 'amount' => 1920000],
            ['invoice_id' => 10, 'segment_id' => null, 'description' => 'Goods and ads', 'quantity' => 1, 'unit_price' => 2904000, 'amount' => 2904000],
        ]);
        foreach ([[4, 1, 1, 735000, '2026-10-30', 'mobile_banking'], [5, 1, 2, 1000000, '2026-10-29', 'mobile_banking'], [6, 1, 3, 265000, '2026-10-30', 'mobile_banking'],
            [7, 2, null, 1000000, '2026-10-30', 'mobile_banking'], [8, 2, 6, 1000000, '2026-10-30', 'mobile_banking'], [9, 2, 6, 920000, '2026-11-01', 'cash'],
            [10, 3, 7, 735000, '2026-11-01', 'cash'], [12, 3, 9, 265000, '2026-11-01', 'cash'], [13, 2, null, 1920000, '2026-11-04', 'cash'], [14, 2, 10, 2904000, '2026-11-07', 'cash']] as [$id, $p, $i, $a, $d, $m]) {
            DB::table('payments')->insert(['id' => $id, 'project_id' => $p, 'invoice_id' => $i, 'amount' => $a, 'paid_on' => $d, 'method' => $m, 'idempotency_key' => (string) Str::uuid(), 'posting_version' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('payment_allocations')->insert([['id' => 5, 'payment_id' => 7, 'segment_id' => 4, 'amount' => 1000000], ['id' => 6, 'payment_id' => 13, 'segment_id' => 5, 'amount' => 1920000]]);

        // The two loans (expenses #2 and #14 reclassified), verified, with no repayment.
        foreach ([[2, 'Loan for Ahsan', 800000, '2026-10-26'], [14, 'Salman Loan', 50000, '2026-11-05']] as [$id, $d, $a, $on]) {
            DB::table('expenses')->insert(['id' => $id, 'category' => 'Loan', 'description' => $d, 'amount' => $a, 'spent_on' => $on, 'status' => 'verified', 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('loan_receivables')->insert([
            ['id' => 1, 'borrower_name' => 'Ahsan', 'borrower_partner_id' => null, 'expense_id' => 2, 'principal' => 800000, 'lent_on' => '2026-10-26', 'status' => 'verified', 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'borrower_name' => 'Salman', 'borrower_partner_id' => null, 'expense_id' => 14, 'principal' => 50000, 'lent_on' => '2026-11-05', 'status' => 'verified', 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $cash = [1 => 1637000, 2 => 1106700, 3 => 2698000, 4 => -198600, 5 => -800000, 6 => 25000, 7 => -4591700, 8 => -280000, 9 => -290000, 10 => -50000, 11 => -394300, 12 => -50000, 13 => -140000,
            14 => -600000, 15 => -28000, 16 => -50000, 17 => -80000, 18 => -1035000, 21 => 57000, 22 => -2153000, 23 => 1800000, 24 => -50000];
        foreach ($cash as $id => $net) {
            // Entries 5 and 16 are the original disbursements of the two loans (expenses #2 and #14), exactly as in the backup.
            $type = in_array($id, [5, 16], true) ? 'expense' : 'backup';
            $source = $id === 5 ? 2 : ($id === 16 ? 14 : $id);
            DB::table('journal_entries')->insert(['id' => $id, 'entry_date' => $id === 24 ? '2026-11-08' : '2026-11-01', 'memo' => 'backup '.$id, 'source_type' => $type, 'source_id' => $source, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('journal_lines')->insert([
                ['journal_entry_id' => $id, 'account' => 'cash', 'debit' => max($net, 0), 'credit' => max(-$net, 0)],
                ['journal_entry_id' => $id, 'account' => $net > 0 ? 'equity.opening' : 'expense', 'debit' => max(-$net, 0), 'credit' => max($net, 0)],
            ]);
        }
    }
}
