<?php

namespace Tests\Support;

use App\Models\CapitalEntry;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Models\Project;
use App\Models\Resolution;
use App\Models\Segment;
use App\Models\SourcingLot;
use App\Models\User;
use App\Services\Ledger;
use App\Services\StandingPolicies;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Test-only fixture for the SellBees audit simulation. It only ever runs inside the isolated PHPUnit database
 * (in-memory SQLite, rebuilt per test) and drives the application THROUGH ITS HTTP ROUTES with real users and roles.
 * All records are clearly dummy and all dates are fixed by the caller.
 *
 * Money is passed to the app as the decimal strings a user would type ("4000.50") and compared here in minor units.
 */
class SellbeesCompany
{
    /** @var array<string, User> */
    public array $users = [];

    public function __construct(public TestCase $t) {}

    public static function boot(TestCase $t): self
    {
        $guard = config('database.connections.'.config('database.default').'.database');
        if (config('database.default') !== 'sqlite' || $guard !== ':memory:' || app()->environment() !== 'testing') {
            throw new \RuntimeException('The audit simulation refuses to run outside the isolated in-memory testing database.');
        }
        config(['admin.email' => 'owner@audit.test', 'admin.password' => 'password-123']);
        $t->seed(DatabaseSeeder::class);

        $co = new self($t);
        $co->users['super'] = User::firstWhere('email', 'owner@audit.test');
        foreach (['board' => 'board_member', 'finance' => 'finance', 'clients' => 'client_management', 'hr' => 'hr', 'auditor' => 'auditor', 'employee' => 'employee'] as $key => $role) {
            $co->users[$key] = self::user($key.'@audit.test', $role);
        }
        // A second Board member (so the proposer/decider and creator/approver rules can be exercised) and a multi-role user.
        $co->users['board2'] = self::user('board2@audit.test', 'board_member');
        $co->users['multi'] = self::user('multi@audit.test', 'finance', 'client_management');
        foreach (['A', 'B', 'C'] as $code) {
            $co->users['partner'.$code] = self::user('partner'.$code.'@audit.test', 'partner');
            $co->users['partner'.$code]->update(['partner_id' => Partner::firstWhere('code', $code)->id]);
        }

        // The Board's signed 3-partner policy (live posting is blocked until it is signed). 40/30/30 profit pool, equal loss thirds.
        PolicyVersion::first()->update(['effective_from' => '2020-01-01', 'approved_at' => now(), 'signed_reference' => 'AUDIT-RES-1',
            'approved_by' => $co->users['board']->id, 'profit_confirmed_at' => now(), 'profit_signed_reference' => 'AUDIT-RES-2']);

        return $co;
    }

    public static function user(string $email, string ...$roles): User
    {
        $user = User::create(['name' => explode('@', $email)[0], 'email' => $email, 'password' => 'password-123']);
        $user->setRoles($roles);

        return $user;
    }

    public function partner(string $code): Partner
    {
        return Partner::firstWhere('code', $code);
    }

    /** Authenticated request as a named user. */
    public function as(string $who): TestCase
    {
        return $this->t->actingAs($this->users[$who]);
    }

    public function post(string $who, string $route, array $data = [], mixed $param = null): TestResponse
    {
        return $this->as($who)->post(route($route, $param), $data);
    }

    /** Invest capital through the real Finance → Board workflow. */
    public function invest(string $code, string $amount, string $date, ?string $reference = null): void
    {
        $this->post('finance', 'finance.capital.store', ['partner_id' => $this->partner($code)->id, 'amount' => $amount, 'paid_on' => $date, 'reference' => $reference ?? 'AUDIT-'.$code.'-'.$date])->assertSessionHasNoErrors();
        $entry = CapitalEntry::where('partner_id', $this->partner($code)->id)->latest('id')->first();
        $this->post('board', 'finance.capital.decide', ['decision' => 'verify'], $entry)->assertSessionHasNoErrors();
    }

    /** Record an expense as Finance and have the Board verify it (unless a standing policy already did). */
    public function expense(string $category, string $description, string $amount, string $date, bool $asset = false): Expense
    {
        $this->post('finance', 'finance.expenses.store', ['category' => $category, 'description' => $description, 'amount' => $amount, 'spent_on' => $date, 'is_asset' => $asset ? 1 : 0])->assertSessionHasNoErrors();
        $e = Expense::latest('id')->first();
        if ($e->status === 'pending') {
            $this->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $e)->assertSessionHasNoErrors();
        }

        return $e->refresh();
    }

    /** Verify every pending expense (Board). */
    public function verifyAllExpenses(): int
    {
        $n = 0;
        foreach (Expense::where('status', 'pending')->orderBy('id')->get() as $e) {
            $this->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $e)->assertSessionHasNoErrors();
            $n++;
        }

        return $n;
    }

    public function client(string $name): Client
    {
        $this->post('clients', 'clients.store', ['name' => $name, 'mobile' => '01700000000'])->assertSessionHasNoErrors();

        return Client::where('name', $name)->firstOrFail();
    }

    public function project(Client $client, string $name = 'Main'): Project
    {
        $this->post('clients', 'clients.projects.store', ['name' => $name, 'status' => 'active', 'currency' => 'BDT', 'started_on' => '2026-01-01'], $client)->assertSessionHasNoErrors();

        return $client->projects()->where('name', $name)->firstOrFail();
    }

    public function segment(Project $project, string $name, string $amount): Segment
    {
        $this->post('clients', 'projects.segments.store', ['name' => $name, 'amount' => $amount], $project)->assertSessionHasNoErrors();

        return $project->segments()->where('name', $name)->firstOrFail();
    }

    public function payInvoice(Invoice $invoice, string $amount, string $date): TestResponse
    {
        return $this->post('finance', 'invoices.payments.store', ['amount' => $amount, 'paid_on' => $date, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()], $invoice);
    }

    public function paySegment(Segment $segment, string $amount, string $date, ?string $key = null): TestResponse
    {
        return $this->post('finance', 'segments.payments.store', ['amount' => $amount, 'paid_on' => $date, 'method' => 'cash', 'idempotency_key' => $key ?? (string) Str::uuid()], $segment);
    }

    /** @param list<array{segment_id?: int|null, description: string, quantity: string, unit_price: string}> $items */
    public function invoice(Project $project, array $items, string $date, string $number): Invoice
    {
        $this->post('finance', 'projects.invoices.store', ['number' => $number, 'issued_on' => $date, 'items' => $items], $project)->assertSessionHasNoErrors();

        return Invoice::where('number', $number)->firstOrFail();
    }

    /** Propose a standing policy (Super Admin) and approve it (Board) through the real resolution workflow. */
    public function standingPolicy(string $key, string $amount = '', ?string $notes = null): Resolution
    {
        $this->post('super', 'finance.resolutions.store', array_filter(['type' => 'other', 'title' => StandingPolicies::title($key), 'amount' => $amount, 'notes' => $notes], fn ($v) => $v !== '' && $v !== null))->assertSessionHasNoErrors();
        $r = Resolution::where('title', StandingPolicies::title($key))->latest('id')->firstOrFail();
        $this->post('board', 'finance.resolutions.decide', ['decision' => 'approve'], $r)->assertSessionHasNoErrors();

        return $r->refresh();
    }

    /** Fund the Emergency Reserve so coverage can be tested (an internal balance, set up as the audit scenario's opening reserve). */
    public function seedReserve(int $minor): void
    {
        Ledger::post('2026-01-01', 'audit opening reserve', 'audit', 1, [['cash', null, $minor, 0], ['fund.emergency', null, 0, $minor]], null);
    }

    /** Create a lot through the controllers and bring it to approved pricing. */
    public function approvedLot(string $title, array $lines, string $profit = '1', string $method = 'fixed', array $costs = [], string $basis = 'value'): SourcingLot
    {
        $this->post('finance', 'sourcing.lots.store', ['title' => $title, 'allocation_basis' => $basis])->assertSessionHasNoErrors();
        $lot = SourcingLot::where('title', $title)->firstOrFail();
        foreach ($lines as $line) {
            $this->post('finance', 'sourcing.lots.lines.store', $line, $lot)->assertSessionHasNoErrors();
        }
        foreach ($costs as $cost) {
            $this->post('finance', 'sourcing.lots.costs.store', $cost + ['kind' => 'actual', 'incurred_on' => now()->toDateString()], $lot)->assertSessionHasNoErrors();
        }
        $this->post('finance', 'sourcing.lots.advance', [], $lot)->assertSessionHasNoErrors();
        $this->post('finance', 'sourcing.lots.pricing', ['method' => $method, 'value' => $profit], $lot)->assertSessionHasNoErrors();
        $this->post('finance', 'sourcing.lots.advance', [], $lot)->assertSessionHasNoErrors();
        $this->post('board', 'sourcing.lots.approve', [], $lot)->assertSessionHasNoErrors();

        return $lot->refresh();
    }
}
