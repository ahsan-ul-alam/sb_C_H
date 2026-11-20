<?php

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\StockMovement;
use App\Models\SupplierPayment;
use App\Services\Inventory;
use App\Services\LotFinancials;
use App\Services\ProjectSourcingReport;
use Database\Seeders\ClientLotDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => config(['admin.email' => 'owner@demo.test', 'admin.password' => 'password-123']));

it('seeds a consistent demo company with client lots in every stage', function () {
    $this->seed(ClientLotDemoSeeder::class);

    $lots = SourcingLot::orderBy('id')->get();
    $profit = fn (SourcingLot $lot) => LotFinancials::for($lot)['profit'];
    expect($lots->map(fn ($l) => $l->status)->all())->toBe(['approved', 'approved', 'in_progress', 'in_progress', 'settled', 'completed', 'settled'])
        ->and($lots->map($profit)->all())->toBe([500_000, 800_000, 780_000, -260_000, 420_000, 850_000, -140_000])
        // Completed lots are received into stock exactly once and nothing is invoiced.
        ->and($lots->filter(fn ($l) => $l->stage() === 'completed')->every(fn ($l) => Inventory::onHand($l->lines()->sole()) === bcadd((string) $l->lines()->sole()->quantity, '0', 2)))->toBeTrue()
        ->and(StockMovement::count())->toBe(3)->and(Invoice::count())->toBe(0)
        // Every recorded payment was verified by the Board and the ledger balances with no duplicate posting.
        ->and(SupplierPayment::count())->toBe(4)->and(Expense::where('status', 'pending')->count())->toBe(0)
        ->and(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0)->and(JournalEntry::count())->toBeGreaterThan(0);

    // The project reports take the completed lots' profit without any invoice: Cafe = 4,200 + 8,500; Acme = −1,400.
    $report = ProjectSourcingReport::build()['projects'];
    $cafe = Project::where('name', 'Cafe Opening Kit')->value('id');
    $acme = Project::where('name', 'Corporate Gifts 2026')->value('id');
    expect($report[$cafe]['recognised_margin'])->toBe(1_270_000)->and($report[$acme]['recognised_margin'])->toBe(-140_000);
});

it('adds nothing when the demo data is already there', function () {
    $this->seed(ClientLotDemoSeeder::class);
    $this->seed(ClientLotDemoSeeder::class);

    expect(SourcingLot::count())->toBe(7);
});
