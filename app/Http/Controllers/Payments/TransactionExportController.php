<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\TransactionExportRequest;
use App\Payments\TransactionReport;
use App\Security\SafeCsv;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionExportController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function __invoke(TransactionExportRequest $request, TransactionReport $report): StreamedResponse|Response
    {
        $filters = $request->filters();
        $entries = $report->entries($filters);

        if ($filters['format'] === 'print') {
            $settings = $this->clubSettings;
            $club = $settings->data();
            $logo = $settings->logoDataUri();
            $printedAt = now()->setTimezone(config('app.display_timezone'));

            return response(view('payments.transactions-print', compact('entries', 'filters', 'report', 'club', 'logo', 'printedAt'))->render(), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'",
            ]);
        }

        return response()->streamDownload(function () use ($entries, $report): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Datum', 'Mitgliedsnummer', 'Mitglied', 'Art', 'Beschreibung', 'Referenz', 'Betrag'], ';', '"', '', "\r\n");
            foreach ($entries as $entry) {
                $member = $entry->account->member;
                fputcsv($output, array_map([SafeCsv::class, 'value'], [
                    $entry->booking_date->format('d.m.Y'),
                    $member->member_number,
                    $member->first_name.' '.$member->last_name,
                    $report->kindLabel($entry->kind),
                    $entry->description,
                    $entry->reference,
                    number_format($entry->amount_cents / 100, 2, ',', ''),
                ]), ';', '"', '', "\r\n");
            }
            fclose($output);
        }, 'kontobuchungen-'.$filters['from'].'-'.$filters['to'].'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
