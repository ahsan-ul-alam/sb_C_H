<?php

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\CapitalEntry;
use App\Models\EmergencyFundRequest;
use App\Models\Expense;
use App\Models\Payout;
use App\Models\Resolution;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\StandingPolicies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('matches HTTP authorisation to the permission map on every parameterless protected route, for every role', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');

    $checked = 0;
    $failures = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $perms = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'can:'))->map(fn ($m) => substr($m, 4))->values();
        $method = in_array('GET', $route->methods(), true) ? 'GET' : (in_array('POST', $route->methods(), true) ? 'POST' : null);
        if ($perms->isEmpty() || str_contains($route->uri(), '{') || ! $method || str_contains($route->uri(), 'logout')) {
            continue;
        }
        foreach ($co->users as $label => $user) {
            $allowed = $perms->every(fn ($p) => $user->can($p));
            $response = $this->actingAs($user)->call($method, '/'.$route->uri(), $method === 'POST' ? [] : []);
            $status = $response->getStatusCode();
            $checked++;
            if ($allowed ? ($status === 403 || $status >= 500) : $status !== 403) {
                $failures[] = "$method /{$route->uri()} as $label: HTTP $status, expected ".($allowed ? 'allowed' : '403');
            }
        }
    }
    expect($checked)->toBeGreaterThan(300)->and($failures)->toBe([]);
});

it('stops one person who holds Finance and Board roles from approving their own work anywhere', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $owner = $co->users['finance'];
    $owner->addRole('board_member');
    expect($owner->can('expense.record'))->toBeTrue()->and($owner->can('expense.verify'))->toBeTrue();

    // Capital and expense.
    $co->post('finance', 'finance.capital.store', ['partner_id' => $co->partner('A')->id, 'amount' => '1000', 'paid_on' => '2026-02-01', 'reference' => 'X'])->assertSessionHasNoErrors();
    $co->post('finance', 'finance.capital.decide', ['decision' => 'verify'], CapitalEntry::first())->assertSessionHas('error');
    $co->post('finance', 'finance.expenses.store', ['category' => 'Misc', 'description' => 'own', 'amount' => '10', 'spent_on' => '2026-02-02'])->assertSessionHasNoErrors();
    $co->post('finance', 'finance.expenses.decide', ['decision' => 'verify'], Expense::first())->assertSessionHas('error');
    expect(CapitalEntry::first()->status)->toBe('pending')->and(Expense::first()->status)->toBe('pending')->and(dr('cash'))->toBe(0);

    // Period: closer cannot verify; preparer cannot approve.
    Carbon::setTestNow('2026-03-10');
    $co->post('board2', 'finance.expenses.decide', ['decision' => 'verify'], Expense::first());
    $co->post('board2', 'finance.capital.decide', ['decision' => 'verify'], CapitalEntry::first());
    $project = $co->project($co->client('Client G'));
    Carbon::setTestNow('2026-02-10');
    $co->paySegment($co->segment($project, 'Retainer', '1000'), '1000', '2026-02-10');
    Carbon::setTestNow('2026-03-10');
    $co->post('finance', 'finance.periods.close', ['month' => '2026-02'])->assertSessionHasNoErrors();
    $period = AccountingPeriod::first();
    $co->post('finance', 'finance.periods.verify', [], $period)->assertSessionHas('error');
    $co->post('board2', 'finance.periods.verify', [], $period)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.periods.prepare', [], $period)->assertSessionHasNoErrors();
    $allocation = Allocation::first();
    $co->post('finance', 'finance.allocations.approve', [], $allocation)->assertSessionHas('error');
    expect($allocation->refresh()->status)->toBe('proposed');

    // Sourcing: pricing submitter cannot approve; requester cannot decide the fund request.
    $co->post('finance', 'sourcing.suppliers.store', ['name' => 'Supplier G']);
    $s = Supplier::first();
    $co->post('finance', 'sourcing.lots.store', ['title' => 'Own lot', 'allocation_basis' => 'value']);
    $lot = SourcingLot::first();
    $co->post('finance', 'sourcing.lots.lines.store', ['supplier_id' => $s->id, 'description' => 'W', 'quantity' => '1', 'unit_price' => '100'], $lot);
    $co->post('finance', 'sourcing.lots.advance', [], $lot);
    $co->post('finance', 'sourcing.lots.pricing', ['method' => 'fixed', 'value' => '10'], $lot);
    $co->post('finance', 'sourcing.lots.advance', [], $lot);
    $co->post('finance', 'sourcing.lots.approve', [], $lot)->assertSessionHas('error');
    $co->post('board2', 'sourcing.lots.approve', [], $lot)->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.lots.costs.store', ['category' => 'Customs', 'amount' => '50', 'kind' => 'actual', 'incurred_on' => '2026-02-11'], $lot);
    $co->seedReserve(1_000_000);
    $co->post('finance', 'sourcing.lots.fund.store', ['amount' => '40', 'reason' => 'loss'], $lot)->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.fund.decide', ['decision' => 'approve'], EmergencyFundRequest::first())->assertSessionHas('error');
    expect(EmergencyFundRequest::first()->status)->toBe('pending')->and(dr('fund.emergency'))->toBe(-1_000_000);

    // Resolutions: the proposer cannot decide their own; policies cannot activate themselves.
    $co->post('finance', 'finance.resolutions.store', ['type' => 'other', 'title' => StandingPolicies::title('period')])->assertSessionHasNoErrors();
    $co->post('finance', 'finance.resolutions.decide', ['decision' => 'approve'], Resolution::first())->assertSessionHas('error');
    expect(StandingPolicies::active('period'))->toBeNull();

    // Payouts: a partner who is also a Board member cannot approve or pay their own payout.
    $pa = $co->users['partnerA'];
    $pa->addRole('board_member');
    $pa->addRole('finance');
    Payout::create(['partner_id' => $co->partner('A')->id, 'amount' => 100, 'requested_by' => $pa->id]);
    $co->post('partnerA', 'finance.payouts.decide', ['decision' => 'approve'], Payout::first())->assertSessionHas('error');
    expect(Payout::first()->status)->toBe('requested');
});

it('limits supplier price overrides, pricing exceptions and corrections to the right permissions', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $co->post('finance', 'sourcing.suppliers.store', ['name' => 'Supplier O']);
    $s = Supplier::first();
    $co->post('finance', 'sourcing.products.store', ['supplier_id' => $s->id, 'name' => 'Cup', 'unit' => 'pcs', 'reference_price' => '3']);
    $product = SupplierProduct::first();
    $co->post('clients', 'sourcing.lots.store', ['title' => 'By ops', 'allocation_basis' => 'value'])->assertSessionHasNoErrors();
    $lot = SourcingLot::first();

    $co->post('clients', 'sourcing.lots.lines.store', ['supplier_product_id' => $product->id, 'quantity' => '10', 'unit_price' => '1'], $lot)->assertSessionHas('error'); // no override permission
    $co->post('clients', 'sourcing.lots.lines.store', ['supplier_product_id' => $product->id, 'quantity' => '10'], $lot)->assertSessionHasNoErrors();
    expect($lot->lines()->first()->unit_price)->toBe(300);
    $co->post('clients', 'sourcing.lots.approve', [], $lot)->assertForbidden();
    $co->post('clients', 'sourcing.lots.settle', [], $lot)->assertForbidden();
    $co->post('clients', 'sourcing.lots.payments.store', ['supplier_id' => $s->id, 'amount' => '1', 'paid_on' => '2026-02-10'], $lot)->assertForbidden();
    $co->post('clients', 'sourcing.lots.fund.store', ['amount' => '1', 'reason' => 'x'], $lot)->assertSessionHas('error'); // may ask, but an unpriced lot has no real loss
    expect(EmergencyFundRequest::count())->toBe(0);
});
