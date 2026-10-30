<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Services\EmergencyFund;
use App\Services\PeriodProcessor;
use App\Services\SourcingObligations;
use App\Services\StandingPolicies;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AutomationController extends Controller
{
    public function index(): View
    {
        $policies = collect(StandingPolicies::DEFINITIONS)->map(fn ($def, $key) => [
            'key' => $key, 'title' => $def[0], 'purpose' => $def[1], 'amount_meaning' => $def[2],
            'active' => StandingPolicies::active($key), 'latest' => StandingPolicies::latest($key),
        ]);

        $months = collect(range(1, 6))->map(fn ($i) => CarbonImmutable::now()->subMonths($i)->format('Y-m'))->map(fn ($m) => ['month' => $m] + PeriodProcessor::readiness($m));
        $releasePolicy = StandingPolicies::active(StandingPolicies::RELEASE);

        return view('finance.automation', [
            'policies' => $policies, 'months' => $months,
            'holdback' => SourcingObligations::details(),
            'stranded' => $releasePolicy ? collect() : EmergencyFund::strandedCandidates(),
            'heldCoverage' => SourcingObligations::heldCoverage(),
            'pendingExpenses' => Expense::where('status', 'pending')->orderBy('spent_on')->limit(20)->get(),
            'recent' => AuditLog::whereIn('action', ['automation.done', 'automation.exception', 'expense.auto_verified', 'order.billed'])->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function run(): RedirectResponse
    {
        $r = PeriodProcessor::run();

        return back()->with($r['exceptions'] ? 'warning' : 'status', count($r['processed']).' action(s) completed, '.count($r['exceptions']).' exception(s) need attention.');
    }

    public function revoke(Request $request, string $key): RedirectResponse
    {
        abort_unless(array_key_exists($key, StandingPolicies::DEFINITIONS), 404);
        StandingPolicies::revoke($key, $request->user());

        return back()->with('status', 'Standing policy revoked. Automatic processing under it stops immediately; nothing already posted changes.');
    }
}
