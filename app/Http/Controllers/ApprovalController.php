<?php

namespace App\Http\Controllers;

use App\Services\PendingApprovals;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $items = collect(PendingApprovals::all())->map(fn ($i) => [...$i, 'can_decide' => PendingApprovals::canDecide($user, $i), 'is_mine' => $i['by'] === $user->id]);

        return view('finance.approvals', ['groups' => $items->groupBy('group'), 'total' => $items->count(), 'mine' => $items->where('can_decide', true)->count()]);
    }
}
