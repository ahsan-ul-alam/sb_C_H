<?php

namespace App\Http\Controllers;

use App\Services\ReconciliationService;
use App\Support\Money;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Read-only view of the reconciliation report. Nothing on this page changes any record. */
class ReconciliationController extends Controller
{
    public function index(): View
    {
        return view('finance.reconciliation', ['rows' => ReconciliationService::report()]);
    }

    public function csv(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['area', 'check', 'recorded', 'recalculated', 'difference', 'ok', 'reason', 'proposed correction']);
            foreach (ReconciliationService::report() as $r) {
                $fmt = fn (int $v): string => $r['unit'] === 'count' ? (string) $v : ($v < 0 ? '-' : '').Money::toDecimal(abs($v));
                fputcsv($out, [$r['area'], $r['check'], $fmt($r['recorded']), $fmt($r['recalculated']), $fmt($r['difference']), $r['ok'] ? 'yes' : 'no', $r['reason'], $r['proposal']]);
            }
            fclose($out);
        }, 'reconciliation.csv', ['Content-Type' => 'text/csv']);
    }
}
