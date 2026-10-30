<?php

namespace App\Http\Controllers;

use App\Models\EmergencyFundRequest;
use App\Models\Partner;
use App\Services\FinancialStatements;
use Illuminate\View\View;

class FinancialStatementsController extends Controller
{
    public function investment(): View
    {
        return view('finance.investment', FinancialStatements::capital() + [
            'utilised' => EmergencyFundRequest::with('lot')->where('status', 'utilised')->latest('id')->get(),
        ]);
    }

    public function position(): View
    {
        return view('finance.position', ['p' => FinancialStatements::position()]);
    }

    public function profitLoss(): View
    {
        $rows = collect(FinancialStatements::profitAndLoss());

        return view('finance.pnl', [
            'rows' => $rows,
            'codes' => Partner::orderBy('sort')->pluck('code'),
            'totals' => [
                'income' => $rows->sum('income'), 'expenses' => $rows->sum('expenses'), 'net' => $rows->sum('net'),
                'salary' => $rows->sum('salary'), 'office' => $rows->sum('office'), 'emergency' => $rows->sum('emergency'), 'partner_pool' => $rows->sum('partner_pool'),
            ],
        ]);
    }
}
