<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Security\SafeCsv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MandateExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        $format = $request->validate(['format' => ['required', 'in:csv,print']])['format'];
        $members = Member::query()->where('payment_method', 'SEPA-Lastschrift')->whereNull('left_at')
            ->where(fn (Builder $query) => $query->whereNull('iban')->orWhere('iban', '')
                ->orWhereNull('mandate_reference')->orWhere('mandate_reference', '')
                ->orWhereNull('mandate_signed_at'))
            ->orderBy('last_name')->orderBy('first_name')->get();
        if ($format === 'print') {
            $settings = ClubSetting::current();
            $club = $settings->data;
            $logo = $settings->logoDataUri();
            $printedAt = now()->setTimezone(config('app.display_timezone'));
            $title = ($club['name'] ?? config('app.name')).' · Fehlende SEPA-Mandate';

            return response(view('payments.missing-mandates', compact('members', 'title', 'logo', 'printedAt'))->render(), 200, [
                'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'",
            ]);
        }

        return response()->streamDownload(function () use ($members): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Mitgliedsnummer', 'Nachname', 'Vorname', 'E-Mail', 'IBAN', 'Mandatsreferenz', 'Mandatsdatum', 'Fehlt'], ';', '"', '', "\r\n");
            foreach ($members as $member) {
                $missing = array_keys(array_filter([
                    'IBAN' => ! $member->iban,
                    'Mandatsreferenz' => ! $member->mandate_reference,
                    'Mandatsdatum' => ! $member->mandate_signed_at,
                ]));
                fputcsv($output, array_map([SafeCsv::class, 'value'], [$member->member_number, $member->last_name, $member->first_name, $member->email, $member->iban, $member->mandate_reference, $member->mandate_signed_at?->format('d.m.Y'), implode(', ', $missing)]), ';', '"', '', "\r\n");
            }
            fclose($output);
        }, 'fehlende-sepa-mandate-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
