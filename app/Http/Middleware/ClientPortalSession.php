<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Services\ClientPortalAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every private portal route. The client is taken ONLY from the portal session (never from the request), the session expires after inactivity, an admin
 * test session is absolute-time limited and tied to the signed-in administrator, and nothing but GET/HEAD (plus logout and exit) can reach the portal.
 */
class ClientPortalSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientId = session('portal.client_id');
        if (! $clientId) {
            return redirect()->route('portal.login');
        }
        $preview = session('portal.preview');
        if ($preview !== null) {
            if (now()->timestamp > (int) $preview['expires'] || ! auth()->check() || (int) auth()->id() !== (int) $preview['admin_id'] || ! auth()->user()->can('portal.preview')) {
                ClientPortalAudit::record('portal.preview.expired', (int) $clientId, 'ended automatically', (int) $preview['admin_id']);
                session()->forget(['portal.client_id', 'portal.preview', 'portal.last']);

                return redirect()->route('portal.preview.form')->with('error', 'The admin test session ended.');
            }
        } elseif (now()->timestamp - (int) session('portal.last', 0) > (int) config('portal.idle_minutes') * 60) {
            session()->invalidate();
            session()->regenerateToken();

            return redirect()->route('portal.login')->with('error', 'For your security you were signed out after a period of inactivity. Please sign in again.');
        }
        $client = Client::find($clientId);
        if ($client === null || ($preview === null && ($client->isArchived() || ! $client->hasVerifiedEmail()))) {
            session()->invalidate();
            session()->regenerateToken();

            return redirect()->route('portal.login');
        }
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD') && ! in_array($request->route()?->getName(), ['portal.logout', 'portal.preview.exit'], true)) {
            abort(405, 'The client portal is read-only.');
        }
        session(['portal.last' => now()->timestamp]);
        $request->attributes->set('portal_client', $client);
        view()->share('portalClient', $client);
        view()->share('portalPreview', $preview);

        return $next($request)->header('Cache-Control', 'no-store, private');
    }
}
