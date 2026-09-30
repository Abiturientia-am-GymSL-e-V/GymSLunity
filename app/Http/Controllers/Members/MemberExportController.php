<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\ExportMembersRequest;
use App\Members\MemberDirectory;
use App\Members\MemberFields;
use App\Members\MemberReportValue;
use App\Members\MemberReportWriter;
use App\Models\Member;
use App\Security\SafeCsv;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberExportController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function __invoke(ExportMembersRequest $request): StreamedResponse|Response
    {
        $data = $request->validated();
        $fields = collect(MemberFields::directoryFields())->keyBy('key')->all();
        $columns = $data['columns'];
        $query = MemberDirectory::query($request->filters())->select(Member::LIST_FIELDS)->with('currentAssignments');
        if ($data['scope'] === 'selected') {
            $query->whereIn('member_number', $data['selected']);
        }
        if (in_array($data['format'], ['xlsx', 'pdf', 'docx', 'print'], true)) {
            return $this->report($query, $fields, $columns, $data['format']);
        }
        $csv = $data['format'] === 'csv';

        return response()->streamDownload(function () use ($query, $fields, $columns, $csv): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Export konnte nicht geöffnet werden.');
            }
            if ($csv) {
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, array_map(fn (string $key): string => SafeCsv::value($key === 'member_number' ? 'Mitgliedsnummer' : $fields[$key]['label']), $columns), ';', '"', '', "\r\n");
            } else {
                fwrite($output, '[');
            }
            $first = true;
            foreach ($query->lazy(500) as $member) {
                $values = MemberFields::reportSnapshot($member);
                $values['member_number'] = $member->member_number;
                $row = [];
                foreach ($columns as $key) {
                    $value = $values[$key] ?? null;
                    if ($csv) {
                        $value = is_bool($value) ? ($value ? 'Ja' : 'Nein') : (is_array($value) ? MemberReportValue::format($value, $fields[$key]) : ($fields[$key]['options'][$value ?? ''] ?? $value ?? ''));
                        $row[$key] = SafeCsv::value($value);
                    } else {
                        $row[$key] = $value;
                    }
                }
                if ($csv) {
                    fputcsv($output, array_values($row), ';', '"', '', "\r\n");
                } else {
                    fwrite($output, ($first ? '' : ',').json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    $first = false;
                }
            }
            if (! $csv) {
                fwrite($output, ']');
            }
            fclose($output);
        }, 'mitglieder-'.Clock::localNow()->format('Y-m-d-His').($csv ? '.csv' : '.json'), [
            'Content-Type' => $csv ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param Builder<Member> $query
     * @param  array<string, array<string, mixed>>  $fields
     * @param  list<string>  $columns
     */
    private function report(Builder $query, array $fields, array $columns, string $format): StreamedResponse|Response
    {
        $limit = $format === 'xlsx' ? 50000 : 12000;
        $count = (clone $query)->count();
        if ($count * count($columns) > $limit) {
            throw ValidationException::withMessages(['scope' => 'Für dieses Format ist die Auswahl zu groß. Bitte weniger Mitglieder oder Spalten wählen oder CSV/JSON verwenden.']);
        }
        $headers = array_map(fn (string $key): string => $key === 'member_number' ? 'Mitgliedsnummer' : $fields[$key]['label'], $columns);
        $rows = [];
        foreach ($query->lazy(500) as $member) {
            $snapshot = MemberFields::reportSnapshot($member);
            $snapshot['member_number'] = $member->member_number;
            $rows[] = array_map(fn (string $key): string => MemberReportValue::format($snapshot[$key] ?? null, $key === 'member_number' ? [] : $fields[$key]), $columns);
        }
        $settings = $this->clubSettings;
        $club = $settings->data();
        $logo = $settings->logoDataUri();
        $printedAt = now()->setTimezone(config('app.display_timezone'));
        $title = ($club['name'] ?? config('app.name')).' · Mitgliederliste';
        if ($format === 'print' || $format === 'pdf') {
            $html = view('exports.list', compact('title', 'headers', 'rows', 'logo', 'printedAt') + ['pdf' => $format === 'pdf'])->render();
            if ($format === 'print') {
                return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'"]);
            }
            $bytes = MemberReportWriter::pdf($html, true);

            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="mitglieder-'.Clock::localNow()->format('Y-m-d-His').'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        return response()->streamDownload(function () use ($format, $headers, $rows, $title, $settings): void {
            if ($format === 'xlsx') {
                MemberReportWriter::excel($headers, $rows);
            } else {
                MemberReportWriter::word($headers, $rows, $title, $settings->logoPath());
            }
        }, 'mitglieder-'.Clock::localNow()->format('Y-m-d-His').'.'.$format, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
