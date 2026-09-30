<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\AssignmentOverviewRequest;
use App\Members\AssignmentReports;
use App\Members\MemberReportValue;
use App\Members\MemberReportWriter;
use App\Security\SafeCsv;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Overviews of offices (with board and vacancies), departments and honors. */
class AssignmentOverviewController extends Controller
{
    public function __construct(private readonly AssignmentReports $reports, private readonly ClubSettings $clubSettings) {}

    public function offices(AssignmentOverviewRequest $request): Response
    {
        $tab = (string) $request->route('tab', 'current');
        $filters = $this->officeFilters($request, $tab);

        return Inertia::render('assignments/Offices', [
            'tab' => $tab,
            'filters' => $filters,
            'fields' => AssignmentReports::fields('office'),
            'offices' => $this->reports->offices($filters, $tab === 'history'),
        ]);
    }

    public function departments(AssignmentOverviewRequest $request): Response
    {
        $filters = $this->departmentFilters($request);

        return Inertia::render('assignments/Departments', [
            'filters' => $filters,
            'fields' => AssignmentReports::fields('department'),
            ...$this->reports->departments($filters),
        ]);
    }

    public function honors(AssignmentOverviewRequest $request): Response
    {
        $tab = (string) $request->route('tab', 'list');
        $filters = $request->filters();
        $jubilees = $tab === 'jubilees';

        return Inertia::render('assignments/Honors', [
            'tab' => $tab,
            'filters' => $jubilees ? [...$filters, 'date' => $this->jubileeUntil($request)] : $filters,
            'fields' => AssignmentReports::fields('honor'),
            'years' => $this->reports->honorYears(),
            'honors' => $jubilees ? null : $this->reports->honors($filters),
            'jubilees' => $jubilees ? $this->reports->jubilees($filters, $this->jubileeUntil($request)) : [],
            'canAssign' => $request->user()?->can('manage-assignments') ?? false,
            'configurationVersion' => $this->clubSettings->fieldsVersion(),
        ]);
    }

    public function exportOffices(AssignmentOverviewRequest $request): StreamedResponse|HttpResponse
    {
        $tab = in_array($request->query('tab'), ['current', 'history', 'date'], true) ? (string) $request->query('tab') : 'current';
        $filters = $this->officeFilters($request, $tab);
        $rows = [];
        foreach ($this->reports->offices($filters, $tab === 'history') as $field) {
            foreach ($field['options'] as $option) {
                foreach ($option['holders'] as $holder) {
                    $rows[] = [$field['label'], $option['label'], $option['board'] ? 'Ja' : 'Nein', ...$this->memberCells($holder), MemberReportValue::period($holder['starts_on'], $holder['ends_on'], false), (string) $holder['note']];
                }
                if ($option['vacant']) {
                    $rows[] = [$field['label'], $option['label'], $option['board'] ? 'Ja' : 'Nein', '', 'Unbesetzt (Pflichtamt)', '', ''];
                }
            }
        }
        $title = match ($tab) {
            'history' => 'Ämter – Verlauf',
            'date' => 'Ämter am '.MemberReportValue::format($filters['date'], ['type' => 'date']),
            default => 'Ämter – aktuell',
        };

        return $this->export($request, $title, 'aemter', ['Feld', 'Amt', 'Vorstand', 'Mitgliedsnummer', 'Name', 'Zeitraum', 'Notiz'], $rows);
    }

    public function exportDepartments(AssignmentOverviewRequest $request): StreamedResponse|HttpResponse
    {
        $filters = $this->departmentFilters($request);
        $report = $this->reports->departments($filters);
        $period = MemberReportValue::format($filters['from'], ['type' => 'date']).' – '.MemberReportValue::format($filters['to'], ['type' => 'date']);
        if ($report['detail'] !== null) {
            $label = $report['groups'][0]['options'][array_search($filters['option'], array_column($report['groups'][0]['options'], 'value'), true)]['label'] ?? $filters['option'];
            $rows = array_map(fn (array $row): array => [...$this->memberCells($row), MemberReportValue::period($row['starts_on'], $row['ends_on'], false), (string) $row['note']], $report['detail']);

            return $this->export($request, $label.' · '.$period, 'abteilung', ['Mitgliedsnummer', 'Name', 'Zeitraum', 'Notiz'], $rows);
        }
        $rows = [];
        foreach ($report['groups'] as $field) {
            foreach ($field['options'] as $option) {
                $rows[] = [$field['label'], $option['label'], (string) $option['members'], (string) $option['joined'], (string) $option['left']];
            }
        }

        return $this->export($request, 'Abteilungen · '.$period, 'abteilungen', ['Feld', 'Abteilung', 'Mitglieder am '.MemberReportValue::format($filters['to'], ['type' => 'date']), 'Eintritte', 'Austritte'], $rows);
    }

    public function exportHonors(AssignmentOverviewRequest $request): StreamedResponse|HttpResponse
    {
        if ($request->query('tab') === 'jubilees') {
            $until = $this->jubileeUntil($request);
            $rows = [];
            foreach ($this->reports->jubilees($request->filters(), $until) as $group) {
                foreach ($group['members'] as $member) {
                    $rows[] = [
                        $group['label'], $group['field_label'], ...$this->memberCells($member), MemberReportValue::format($member['joined_at'], ['type' => 'date']),
                        MemberReportValue::format($member['jubilee_on'], ['type' => 'date']), $member['due'] ? 'Fällig' : 'Bevorstehend',
                    ];
                }
            }

            return $this->export($request, 'Fällige Jubiläen bis '.MemberReportValue::format($until, ['type' => 'date']), 'jubilaeen', ['Ehrung', 'Feld', 'Mitgliedsnummer', 'Name', 'Eintritt', 'Jubiläum am', 'Status'], $rows);
        }
        $rows = array_map(fn (array $row): array => [
            MemberReportValue::period($row['starts_on'], null, true), $row['label'], $row['field'], ...$this->memberCells($row), (string) $row['note'],
        ], $this->reports->allHonors($request->filters()));

        return $this->export($request, 'Ereignisse und Ehrungen', 'ehrungen', ['Datum', 'Ehrung', 'Feld', 'Mitgliedsnummer', 'Name', 'Notiz'], $rows);
    }

    /** Jubilees are listed up to the given day, by default the end of the current year. */
    private function jubileeUntil(AssignmentOverviewRequest $request): string
    {
        $date = $request->validated('date');

        return is_string($date) ? $date : Clock::today()->endOfYear()->toDateString();
    }

    /** @return array{field: string, option: string, date: string, from: string|null, to: string|null, year: int|null, board: bool, members: string} */
    private function officeFilters(AssignmentOverviewRequest $request, string $tab): array
    {
        $filters = $request->filters();

        return $tab === 'current' ? [...$filters, 'date' => Clock::todayString()] : $filters;
    }

    /** @return array{field: string, option: string, date: string, from: string, to: string, year: int|null, board: bool, members: string} */
    private function departmentFilters(AssignmentOverviewRequest $request): array
    {
        $filters = $request->filters();
        $today = Clock::today();
        $to = $filters['to'] ?? $today->toDateString();
        $from = $filters['from'] ?? min($today->startOfYear()->toDateString(), $to);

        return [...$filters, 'from' => $from, 'to' => $to];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function memberCells(array $row): array
    {
        return [(string) $row['member_number'], $row['name'].($row['current_member'] ? '' : ' (kein aktuelles Mitglied)')];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function export(Request $request, string $title, string $filename, array $headers, array $rows): StreamedResponse|HttpResponse
    {
        $filename .= '-'.Clock::localNow()->format('Y-m-d-His');
        if ($request->query('format') === 'pdf') {
            $club = $this->clubSettings->data();
            $html = view('exports.list', [
                'title' => ($club['name'] ?? config('app.name')).' · '.$title, 'headers' => $headers, 'rows' => $rows,
                'logo' => $this->clubSettings->logoDataUri(), 'printedAt' => now()->setTimezone(config('app.display_timezone')),
                'pdf' => true, 'countLabel' => 'Einträge', 'emptyText' => 'Keine Einträge für diese Auswahl.',
            ])->render();

            return response(MemberReportWriter::pdf($html, true), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->streamDownload(function () use ($headers, $rows): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Export konnte nicht geöffnet werden.');
            }
            fwrite($output, "\xEF\xBB\xBF");
            foreach ([$headers, ...$rows] as $row) {
                fputcsv($output, array_map(SafeCsv::value(...), $row), ';', '"', '', "\r\n");
            }
            fclose($output);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
