<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BankingReport;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function banking(Request $request, BankingReport $report): View
    {
        $data = $report->build(includeUnconsented: $request->boolean('all'));
        Audit::log($request->user(), 'banking_report_viewed', ['included' => $data['meta']['contracts_included'], 'all' => $request->boolean('all')]);

        return view('admin.reports.banking', ['report' => $data, 'all' => $request->boolean('all')]);
    }

    /** CSV export of one section: register | summaries | receivables | payments | forecast. */
    public function export(Request $request, BankingReport $report): StreamedResponse
    {
        $part = $request->validate(['part' => ['required', 'in:register,summaries,receivables,payments,forecast']])['part'];
        $data = $report->build(includeUnconsented: $request->boolean('all'));
        $rows = $part === 'forecast'
            ? collect($data['portfolio']['forecast_by_month'])->map(fn ($r, $ym) => ['month' => $ym, 'forecast' => round($r['amount'], 2), 'cancellable' => round($r['cancellable'], 2), 'committed' => round($r['amount'] - $r['cancellable'], 2)])->values()
            : collect($data[$part]);
        Audit::log($request->user(), 'banking_report_exported', ['part' => $part, 'rows' => $rows->count()]);

        $filename = 'banking-'.$part.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['# '.config('company.name').' · issued '.$data['meta']['issued_at'].' · base currency '.$data['meta']['base_currency'].' · not certified · verification '.substr($data['meta']['verification_hash'], 0, 16)]);
            if ($rows->isEmpty()) {
                fputcsv($out, ['No rows']);
            } else {
                fputcsv($out, array_keys($rows->first()));
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : (is_float($v) ? number_format($v, 2, '.', '') : self::safeCell($v)), $row));
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Text that a spreadsheet would read as a formula (=, +, -, @, tab, CR) is prefixed with an apostrophe, so a
     * client-typed payment reference can never run as a formula when the CSV is opened in Excel.
     */
    private static function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
