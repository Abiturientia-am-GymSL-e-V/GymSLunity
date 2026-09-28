<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Members\MemberReportWriter;
use App\Models\ClubSetting;
use App\Statistics\StatisticsReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatisticsController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to, $asOf] = $this->dates($request);
        $tab = (string) $request->route('tab', 'overview');
        $tabs = [
            'overview' => ['Übersicht', route('statistics')],
            'members' => ['Mitglieder', route('statistics.members')],
            'finances' => ['Finanzen', route('statistics.finances')],
            'quality' => ['Datenqualität', route('statistics.quality')],
        ];
        abort_unless(isset($tabs[$tab]), 404);

        return Inertia::render('Statistics', [
            'activeTab' => $tab,
            'navigationBreadcrumb' => ['title' => $tabs[$tab][0], 'href' => $tabs[$tab][1]],
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'as_of' => $asOf->toDateString(),
            ],
            ...(new StatisticsReport($from, $to, $asOf))->build(),
        ]);
    }

    public function stockCsv(Request $request): StreamedResponse
    {
        [$from, $to, $asOf] = $this->dates($request);
        $rows = (new StatisticsReport($from, $to, $asOf))->stockReport();

        return response()->streamDownload(function () use ($rows, $asOf): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Bestandsmeldung zum', $asOf->format('d.m.Y')], ';');
            fputcsv($output, ['Geburtsjahr', 'Weiblich', 'Männlich', 'Divers', 'Ohne Angabe', 'Gesamt'], ';');
            foreach ($rows as $row) {
                fputcsv($output, [$row['label'], $row['female'], $row['male'], $row['diverse'], $row['unspecified'], $row['total']], ';');
            }
            fclose($output);
        }, 'bestandsmeldung-'.$asOf->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function pdf(Request $request): HttpResponse
    {
        [$from, $to, $asOf] = $this->dates($request);
        $settings = ClubSetting::current();
        $report = (new StatisticsReport($from, $to, $asOf))->build();
        $html = view('statistics.report', [
            ...$report,
            'from' => $from,
            'to' => $to,
            'asOf' => $asOf,
            'club' => $settings->data,
            'logo' => $settings->logoDataUri(),
            'createdAt' => now()->setTimezone(config('app.display_timezone')),
        ])->render();
        $filename = 'auswertungen-'.$from->format('Y-m-d').'-bis-'.$to->format('Y-m-d').'.pdf';

        return response(MemberReportWriter::pdf($html, true), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable} */
    private function dates(Request $request): array
    {
        $today = CarbonImmutable::today();
        $defaults = [
            'from' => $today->subMonths(11)->startOfMonth()->toDateString(),
            'to' => $today->toDateString(),
            'as_of' => $today->toDateString(),
        ];
        $request->merge([
            'from' => $request->input('from', $defaults['from']),
            'to' => $request->input('to', $defaults['to']),
            'as_of' => $request->input('as_of', $defaults['as_of']),
        ]);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['from']);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['to']);
        $asOf = CarbonImmutable::createFromFormat('!Y-m-d', $data['as_of']);
        if ($from->startOfMonth()->diffInMonths($to->startOfMonth()) > 35) {
            abort(422, 'Der Auswertungszeitraum darf höchstens 36 Monate umfassen.');
        }

        return [$from, $to, $asOf];
    }
}
