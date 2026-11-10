<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientFinancials;
use App\Services\ClientPortalAudit;
use App\Services\ClientPortalData;
use App\Services\ClientPortalOtp;
use App\Services\SbNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * The Client Self-Service Portal. Public part: SB number -> emailed code -> client session. Private part: read-only pages for ONE client, always taken from
 * the portal session. Staff test access is a separate, authenticated and audited workflow (see previewStart).
 */
class ClientPortalController extends Controller
{
    public function login(): View|RedirectResponse
    {
        return session('portal.client_id') ? redirect()->route('portal.dashboard') : view('portal.login');
    }

    public function requestCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['sb_number' => ['required', 'string', 'max:20']]);
        session()->forget('portal.challenge');
        $result = ClientPortalOtp::request($data['sb_number'], ClientPortalOtp::binding(), (string) $request->ip());
        if ($result['throttled']) {
            return back()->withInput()->with('error', 'Too many requests. Please wait a few minutes and try again.');
        }
        session(['portal.masked' => $result['masked'], 'portal.sb' => strtoupper(trim($data['sb_number'])), 'portal.requested_at' => now()->timestamp]);

        return redirect()->route('portal.verify');
    }

    public function verifyForm(): View|RedirectResponse
    {
        if (! session('portal.masked')) {
            return redirect()->route('portal.login');
        }

        return view('portal.verify', ['masked' => session('portal.masked'), 'cooldown' => max(0, (int) config('portal.resend_cooldown_seconds') - (now()->timestamp - (int) session('portal.requested_at', 0)))]);
    }

    public function resend(Request $request): RedirectResponse
    {
        if (! session('portal.sb')) {
            return redirect()->route('portal.login');
        }
        $result = ClientPortalOtp::request(session('portal.sb'), ClientPortalOtp::binding(), (string) $request->ip());
        if ($result['throttled']) {
            return back()->with('error', 'Too many requests. Please wait a few minutes and try again.');
        }
        session(['portal.requested_at' => now()->timestamp]);

        return back()->with('status', 'If the details are right, a new code is on its way. The earlier code no longer works.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:12']]);
        $client = ClientPortalOtp::verify(session('portal.challenge'), $request->input('code'), ClientPortalOtp::binding());
        if ($client === null) {
            return back()->with('error', 'That code is not valid or has expired. Check the latest email, or ask for a new code.');
        }
        $request->session()->regenerate();
        session()->forget(['portal.challenge', 'portal.masked', 'portal.sb', 'portal.requested_at']);
        session(['portal.client_id' => $client->id, 'portal.last' => now()->timestamp]);

        return redirect()->route('portal.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        ClientPortalAudit::record('portal.logout', (int) session('portal.client_id'));
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login')->with('status', 'You have been signed out.');
    }

    public function dashboard(Request $request): View
    {
        return view('portal.dashboard', ['o' => ClientPortalData::overview($this->client($request))]);
    }

    public function profile(Request $request): View
    {
        return view('portal.profile', ['p' => ClientPortalData::profile($this->client($request))]);
    }

    public function projects(Request $request): View
    {
        $client = $this->client($request);
        $projects = ClientPortalData::projects($client);
        $selected = $projects->firstWhere('id', (int) $request->query('project'));

        return view('portal.projects', ['projects' => $projects, 'selected' => $selected, 'cards' => $selected ? [] : ClientPortalData::projectCards($client), 'detail' => ClientPortalData::projectDetail($client, $selected?->id)]);
    }

    public function inventory(Request $request): View
    {
        $client = $this->client($request);
        $projects = ClientPortalData::projects($client);
        $selected = $projects->firstWhere('id', (int) $request->query('project'));
        $term = mb_strtolower(trim((string) $request->query('q')));
        $rows = ClientPortalData::positions($client, $selected?->id)->when($term !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['line']->description), $term)));

        return view('portal.inventory', ['projects' => $projects, 'selected' => $selected, 'rows' => $rows]);
    }

    public function orders(Request $request): View
    {
        $client = $this->client($request);

        return view('portal.orders', ['orders' => ClientPortalData::orders($client, $request), 'projects' => ClientPortalData::projects($client), 'statuses' => ClientPortalData::orderStatuses(), 'counts' => ClientPortalData::orderCounts($client)]);
    }

    public function invoices(Request $request): View
    {
        $client = $this->client($request);

        return view('portal.invoices', ['invoices' => ClientPortalData::invoices($client), 'summary' => ClientPortalData::invoiceSummary($client)]);
    }

    public function invoicePdf(Request $request, int $invoice): Response
    {
        $own = ClientPortalData::invoice($this->client($request), $invoice);
        abort_if($own === null, 404);

        return app(InvoiceController::class)->pdf($request, $own);
    }

    public function finance(Request $request): View
    {
        $client = $this->client($request);
        $fin = ClientFinancials::for($client);
        $category = (string) $request->query('category');
        $valid = array_merge(array_keys($fin['by_category']), ['mixed']);
        $category = in_array($category, $valid, true) ? $category : '';
        $invoices = collect($fin['invoices'])->when($category !== '', fn ($c) => $c->filter(fn ($i) => $category === 'mixed' ? $i['category'] === null : $i['category'] === $category))->reverse()->values();

        return view('portal.finance', ['fin' => $fin, 'category' => $category, 'invoices' => $invoices, 'o' => ClientPortalData::overview($client)]);
    }

    public function sales(Request $request): View
    {
        $client = $this->client($request);

        return view('portal.sales', ['e' => ClientPortalData::economics($client), 'monthly' => ClientPortalData::monthlySales($client), 'billing' => ClientPortalData::productBilling($client), 'fin' => ClientFinancials::for($client)]);
    }

    public function previewForm(): View
    {
        return view('portal.preview', ['staticCode' => ! app()->environment('production'), 'enabled' => ! app()->environment('production') || (bool) config('portal.admin_preview_in_production')]);
    }

    /**
     * Admin test access. The signed-in administrator must hold portal.preview; the target is chosen by SB number; the owner's test code (outside production)
     * or the administrator's own password (production, only when explicitly enabled) is an additional confirmation, never the credential. Rate limited and audited.
     */
    public function previewStart(Request $request): RedirectResponse
    {
        $data = $request->validate(['sb_number' => ['required', 'string', 'max:20'], 'reason' => ['required', 'string', 'min:10', 'max:200'], 'confirmation' => ['required', 'string', 'max:200']]);
        $user = $request->user();
        $key = 'portal-preview:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('error', 'Too many attempts. Wait a few minutes.');
        }
        RateLimiter::hit($key, 600);
        $production = app()->environment('production');
        $confirmed = $production
            ? (bool) config('portal.admin_preview_in_production') && Hash::check($data['confirmation'], (string) $user->password)
            : filled(config('portal.test_code_hash')) && Hash::check($data['confirmation'], (string) config('portal.test_code_hash'));
        $client = SbNumber::isValid(strtoupper($data['sb_number'])) ? Client::where('sb_number', strtoupper($data['sb_number']))->first() : null;
        if (! $confirmed || $client === null) {
            ClientPortalAudit::record('portal.preview.refused', $client?->id, $confirmed ? 'unknown SB number' : 'confirmation failed', $user->id);

            return back()->withInput($request->only('sb_number', 'reason'))->with('error', 'Could not start the test session. Check the SB number and the confirmation.');
        }
        RateLimiter::clear($key);
        ClientPortalAudit::record('portal.preview.start', $client->id, 'reason: '.$data['reason'], $user->id);
        session(['portal.client_id' => $client->id, 'portal.last' => now()->timestamp, 'portal.preview' => ['admin_id' => $user->id, 'expires' => now()->addMinutes((int) config('portal.preview_minutes'))->timestamp]]);

        return redirect()->route('portal.dashboard');
    }

    public function previewExit(Request $request): RedirectResponse
    {
        $preview = session('portal.preview');
        ClientPortalAudit::record('portal.preview.exit', (int) session('portal.client_id'), null, $preview['admin_id'] ?? null);
        session()->forget(['portal.client_id', 'portal.preview', 'portal.last']);

        return redirect()->route('portal.preview.form')->with('status', 'The admin test session ended.');
    }

    private function client(Request $request): Client
    {
        return $request->attributes->get('portal_client');
    }
}
