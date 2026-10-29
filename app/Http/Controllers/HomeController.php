<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Send each role to the first screen it may use.
     */
    public function __invoke(Request $request, DashboardController $clientHub)
    {
        $user = $request->user();

        return match (true) {
            $user->can('clienthub') => $clientHub($request),
            $user->can('finance.view') => redirect()->route('finance.dashboard'),
            $user->can('own.view') && $user->partner_id => redirect()->route('finance.me'),
            $user->can('resolution.create') => redirect()->route('finance.resolutions'),
            default => response('Your account has no assigned access yet. Contact an administrator.', 403),
        };
    }
}
