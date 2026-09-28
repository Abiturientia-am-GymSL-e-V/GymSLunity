<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use App\Payments\TransactionReport;
use App\Security\SafeCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionExportController extends Controller
{
    public function __invoke(Request $request, TransactionReport $report): StreamedResponse|Response
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', 'string', 'max:100'],
            'direction' => ['nullable', 'in:all,charge,credit'],
            'format' => ['required', 'in:csv,print'],
        ]);
        $entries = $report->entries($filters);

        if ($filters['format'] === 'print') {
            $settings = ClubSetting::current();
            $club = $settings->data;
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
