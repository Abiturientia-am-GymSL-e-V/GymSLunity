<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Configuration\ClubSettings;
use App\Members\MemberReportWriter;
use App\Models\MemberFieldDefinition;
use App\Security\SafeCsv;
use App\Statistics\StatisticsReport;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatisticsController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

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
        [$department] = $this->department($request);

        return Inertia::render('Statistics', [
            'activeTab' => $tab,
            'navigationBreadcrumb' => ['title' => $tabs[$tab][0], 'href' => $tabs[$tab][1]],
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'as_of' => $asOf->toDateString(),
                'department' => $department,
            ],
            'departmentChoices' => $this->departmentChoices(),
            'periods' => $this->periods(),
            ...(new StatisticsReport($from, $to, $asOf, $department))->build(),
        ]);
    }

    /**
     * Quick selections for the running and the previous financial year. The
     * running year ends today because future dates are not evaluated.
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    private function periods(): array
    {
        $today = Clock::today();
        $current = $this->clubSettings->fiscalYear($today);
        $previous = $current->previous();
        $name = $current->start->month === 1 ? 'Jahr' : 'Geschäftsjahr';

        return [
            ['label' => 'Letzte 12 Monate', 'from' => $today->subMonths(11)->startOfMonth()->toDateString(), 'to' => $today->toDateString()],
            ['label' => $name.' '.$current->label(), 'from' => $current->start->toDateString(), 'to' => $today->toDateString()],
            ['label' => $name.' '.$previous->label(), 'from' => $previous->start->toDateString(), 'to' => $previous->end->toDateString()],
        ];
    }

    public function stockCsv(Request $request): StreamedResponse
    {
        [$from, $to, $asOf] = $this->dates($request);
        [$department, $departmentLabel] = $this->department($request);
        $rows = (new StatisticsReport($from, $to, $asOf, $department))->stockReport();

        return response()->streamDownload(function () use ($rows, $asOf, $departmentLabel): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Bestandsmeldung zum', $asOf->format('d.m.Y'), ...($departmentLabel !== '' ? ['Abteilung', SafeCsv::value($departmentLabel)] : [])], ';');
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
        $settings = $this->clubSettings;
        [$department, $departmentLabel] = $this->department($request);
        $report = (new StatisticsReport($from, $to, $asOf, $department))->build();
        $html = view('statistics.report', [
            ...$report,
            'departmentLabel' => $departmentLabel,
            'from' => $from,
            'to' => $to,
            'asOf' => $asOf,
            'club' => $settings->data(),
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

    /**
     * Department the stock report is limited to, as "field_key:option".
     *
     * @return array{0: string, 1: string} value and label, empty for all members
     */
    private function department(Request $request): array
    {
        $choices = $this->departmentChoices();
        $value = (string) ($request->validate(['department' => ['nullable', 'string', Rule::in(array_column($choices, 'value'))]])['department'] ?? '');

        return [$value, (string) (collect($choices)->firstWhere('value', $value)['label'] ?? '')];
    }

    /** @return list<array{value: string, label: string}> options of active department fields */
    private function departmentChoices(): array
    {
        $fields = MemberFieldDefinition::query()->where('type', 'department')->where('is_active', true)->orderBy('position')->orderBy('id')->get();
        $choices = [];
        foreach ($fields as $field) {
            foreach ($field->options as $option) {
                $choices[] = ['value' => $field->key.':'.$option['value'], 'label' => ($fields->count() > 1 ? $field->label.': ' : '').$option['label']];
            }
        }

        return $choices;
    }

    /** @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable} */
    private function dates(Request $request): array
    {
        $today = Clock::today();
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
            'to' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Clock::todayString()],
            'as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Clock::todayString()],
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
