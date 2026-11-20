<?php

namespace Database\Seeders;

use App\Models\CapitalEntry;
use App\Models\Client;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Audit;
use App\Services\ClientLot;
use App\Services\ExpenseService;
use App\Services\ExpenseVerifier;
use App\Services\InvestmentService;
use App\Services\LotFinancials;
use App\Services\SourcingPayments;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LOCAL DEMO DATA for the client-lot workflow: a small company with staff, partner capital, two client projects, three suppliers and lots in
 * every stage (Processing, In order, Completed — profit and loss, paid and unpaid). Everything is created THROUGH THE APPLICATION'S OWN
 * SERVICES (lot workflow, supplier payments, receiving, Board verification), so the ledger, stock and payment records are real and consistent;
 * nothing is written to the books directly.
 *
 * Refuses to run outside a local/testing environment, does nothing if the demo data is already there, and runs in one transaction. Demo
 * records are named "(demo)". The three-partner policy is marked signed with an explicit DEMO reference only when it is still unsigned, because
 * the ledger refuses live posting until it is: that is a local convenience, not a Board signature.
 *
 *   php artisan db:seed --class=ClientLotDemoSeeder
 */
class ClientLotDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private User $finance;

    private User $board;

    private FinancialAccount $cash;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data is for a local database only (APP_ENV is '.app()->environment().').');
        }
        if (Supplier::where('name', 'Dhaka Ceramics (demo)')->exists()) {
            $this->command?->warn('Demo data is already present; nothing was added.');

            return;
        }
        $this->call(DatabaseSeeder::class); // admin, partners A/B/C and the policy draft (safe to re-run)

        $lots = DB::transaction(function () {
            $this->staff();
            $this->books();

            return $this->lots();
        });

        Auth::forgetUser();
        $this->report($lots);
    }

    private function staff(): void
    {
        $this->finance = $this->user('Farhana (Finance)', 'finance@demo.test', 'finance');
        $this->board = $this->user('Rahim (Board)', 'board@demo.test', 'board_member');
        $this->user('Sadia (Operations)', 'ops@demo.test', 'client_management')->forceFill(['all_projects' => true])->save();
        $this->user('Auditor', 'auditor@demo.test', 'auditor');
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => self::PASSWORD]);
        $user->setRoles([$role]);

        return $user;
    }

    /** Cash and bank accounts, a signed (demo) policy and verified partner capital, so payments can actually be made and posted. */
    private function books(): void
    {
        $policy = PolicyVersion::firstOrFail();
        if ($policy->approved_at === null) {
            $policy->update(['effective_from' => today()->startOfYear(), 'approved_by' => $this->board->id, 'approved_at' => now(), 'signed_reference' => 'DEMO-LOCAL (not a Board signature)',
                'profit_confirmed_by' => $this->board->id, 'profit_confirmed_at' => now(), 'profit_signed_reference' => 'DEMO-LOCAL (not a Board signature)']);
        }

        $this->cash = FinancialAccount::firstOrCreate(['name' => 'Office Cash'], ['type' => 'cash', 'is_default' => true, 'is_active' => true, 'allow_negative' => false]);
        $bank = FinancialAccount::firstOrCreate(['name' => 'City Bank'], ['type' => 'bank', 'is_default' => false, 'is_active' => true, 'allow_negative' => false]);

        foreach (['A' => [300_000, $this->cash], 'B' => [200_000, $bank], 'C' => [200_000, $bank]] as $code => [$bdt, $account]) {
            Auth::setUser($this->finance);
            $entry = CapitalEntry::create(['partner_id' => Partner::where('code', $code)->value('id'), 'kind' => 'contribution', 'amount' => $bdt * 100, 'paid_on' => $this->day(18),
                'reference' => 'DEMO-CAP-'.$code, 'financial_account_id' => $account->id, 'method' => 'bank_transfer', 'created_by' => $this->finance->id]);
            Audit::log('capital.recorded', 'capital '.$entry->id, 'contribution '.$entry->amount);
            // Verified by the Board (a different person), exactly as CapitalController::decide does it.
            Auth::setUser($this->board);
            $entry->update(['status' => 'verified', 'verified_by' => $this->board->id, 'verified_at' => now()]);
            InvestmentService::post($entry, $this->board->id);
            Audit::log('capital.verified', 'capital '.$entry->id);
        }
    }

    /** @return list<SourcingLot> */
    private function lots(): array
    {
        $acme = $this->project('Acme Retail (demo)', '01700000001', 'Corporate Gifts 2026');
        $cafe = $this->project('Green Leaf Cafe (demo)', '01700000002', 'Cafe Opening Kit');

        $ceramics = Supplier::create(['name' => 'Dhaka Ceramics (demo)', 'phone' => '01811000001', 'address' => 'Tejgaon, Dhaka']);
        $textiles = Supplier::create(['name' => 'Bengal Textiles (demo)', 'phone' => '01811000002', 'address' => 'Narayanganj']);
        $print = Supplier::create(['name' => 'PrintHub BD (demo)', 'phone' => '01811000003', 'address' => 'Nilkhet, Dhaka']);
        $mug = $this->product($ceramics, 'Coffee Mug', 150);
        $cup = $this->product($ceramics, 'Tea Cup', 95);
        $tote = $this->product($textiles, 'Tote Bag', 220);
        $apron = $this->product($textiles, 'Apron', 340);
        $notebook = $this->product($print, 'Notebook A5', 120);

        $lots = [];
        // Processing — created, not yet ordered.
        $lots[] = $this->create($mug, $acme, 100, 150, 20_000);
        $lots[] = $this->create($tote, $acme, 200, 220, 52_000);
        // In order — supplier paid in full, awaiting the goods.
        $lots[] = $this->order($this->create($notebook, $acme, 300, 120, 45_000), 1_200, supplierPaidOn: 6);
        // In order — expected LOSS (cost 17,600 against a 15,000 budget); the supplier is not paid yet, which never blocks the lot.
        $lots[] = $this->order($this->create($apron, $cafe, 50, 340, 15_000), 600);
        // Completed — the reference example: 100 × 150 + 800 delivery = 15,800 cost, 20,000 budget, profit 4,200. Payments entered separately.
        $lots[] = $this->complete($this->order($this->create($mug, $cafe, 100, 150, 20_000), 800, supplierPaidOn: 9), deliveryPaid: true, grn: 'GRN-1001');
        // Completed — the delivery charge is not paid yet.
        $lots[] = $this->complete($this->order($this->create($cup, $cafe, 400, 95, 48_000), 1_500, supplierPaidOn: 8), deliveryPaid: false, grn: 'GRN-1002');
        // Completed with a LOSS — 30 × 340 + 700 = 10,900 cost against a 9,500 budget: loss 1,400.
        $lots[] = $this->complete($this->order($this->create($apron, $acme, 30, 340, 9_500), 700, supplierPaidOn: 2), deliveryPaid: true, grn: 'GRN-1003');

        $this->verifyPendingExpenses(); // the Board verifies the payments, so they reach the ledger

        return $lots;
    }

    private function project(string $client, string $mobile, string $name): Project
    {
        $client = Client::create(['name' => $client, 'mobile' => $mobile, 'email' => str($client)->before(' (')->slug().'@demo.test', 'address' => 'Dhaka']);

        return $client->projects()->create(['name' => $name, 'status' => 'active', 'currency' => 'BDT', 'started_on' => today()->startOfYear()]);
    }

    private function product(Supplier $supplier, string $name, int $bdt): SupplierProduct
    {
        return SupplierProduct::create(['supplier_id' => $supplier->id, 'name' => $name, 'unit' => 'pcs', 'reference_price' => $bdt * 100, 'is_active' => true]);
    }

    private function create(SupplierProduct $product, Project $project, int $quantity, int $unitCostBdt, int $budgetBdt): SourcingLot
    {
        Auth::setUser($this->finance);

        return ClientLot::create(['supplier_id' => $product->supplier_id, 'supplier_product_id' => $product->id, 'project_id' => $project->id,
            'quantity' => (string) $quantity, 'unit_cost' => $unitCostBdt * 100, 'client_budget' => $budgetBdt * 100], $this->finance);
    }

    private function order(SourcingLot $lot, int $deliveryBdt, ?int $supplierPaidOn = null): SourcingLot
    {
        Auth::setUser($this->finance);
        ClientLot::markInOrder($lot, $deliveryBdt * 100, $this->finance);
        if ($supplierPaidOn !== null) {
            SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => (int) $lot->lines()->value('supplier_id'), 'amount' => LotFinancials::for($lot)['supplier_outstanding'],
                'paid_on' => $this->day($supplierPaidOn), 'method' => 'bank_transfer', 'reference' => 'DEMO-PAY-'.$lot->number, 'evidence_reference' => 'DEMO-INV-'.$lot->number,
                'purchase_order' => $lot->number, 'financial_account_id' => $this->cash->id, 'idempotency_key' => 'demo-supplier-'.$lot->number], $this->finance);
        }

        return $lot->refresh();
    }

    /** The delivery payment is entered separately, the way the admins record payments, and the goods are then received (completion records no money). */
    private function complete(SourcingLot $lot, bool $deliveryPaid, string $grn): SourcingLot
    {
        Auth::setUser($this->finance);
        if ($deliveryPaid) {
            foreach ($lot->costs()->where('kind', 'actual')->get() as $cost) {
                SourcingPayments::payCost($cost, $this->day(2), $this->finance, $this->cash->id);
            }
        }
        ClientLot::complete($lot, ['arrived_on' => $this->day(2), 'reference' => $grn], $this->finance);

        return $lot->refresh();
    }

    /** The same steps as ExpenseController::decide when a Board member verifies a pending expense. */
    private function verifyPendingExpenses(): void
    {
        Auth::setUser($this->board);
        foreach (Expense::where('status', 'pending')->orderBy('id')->get() as $expense) {
            $expense->update(['status' => 'verified', 'verified_by' => $this->board->id, 'verified_at' => now()]);
            ExpenseVerifier::post($expense, $this->board->id);
            SourcingPayments::onCorrectionDecided($expense, true);
            ExpenseService::onDecided($expense, true);
            Audit::log('expense.verified', 'expense '.$expense->id);
        }
    }

    /** A date this month, $daysAgo days back but never before the 1st (so nothing lands in a month that may be closed). */
    private function day(int $daysAgo): string
    {
        $date = CarbonImmutable::today()->subDays($daysAgo);

        return ($date->month === today()->month ? $date : CarbonImmutable::today()->startOfMonth())->toDateString();
    }

    /** @param list<SourcingLot> $lots */
    private function report(array $lots): void
    {
        $this->command?->info('Client-lot demo data created.');
        $this->command?->table(['Lot', 'Product', 'Project', 'Stage', 'Total cost', 'Budget', 'Profit'], collect($lots)->map(function (SourcingLot $lot) {
            $f = LotFinancials::for($lot->refresh());

            return [$lot->number, $lot->lines()->value('description'), $lot->project?->name, $lot->stageLabel().($lot->status === 'settled' ? ' (settled)' : ''),
                Money::format($f['landed']), Money::format($f['revenue']), Money::format($f['profit'])];
        })->all());
        $this->command?->table(['Sign in as', 'Password', 'Role'], [
            ['finance@demo.test', self::PASSWORD, 'Finance — runs the whole lot workflow'],
            ['board@demo.test', self::PASSWORD, 'Board member'],
            ['ops@demo.test', self::PASSWORD, 'Operations (client management)'],
            ['auditor@demo.test', self::PASSWORD, 'Auditor (read-only)'],
        ]);
    }
}
