<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\AuditLogFilterRequest;
use App\Members\MemberReportWriter;
use App\Security\AuditLog;
use App\Security\SafeCsv;
use App\Support\Clock;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    private const HEADERS = ['Zeitpunkt', 'Bereich', 'Aktion', 'Ergebnis', 'Person', 'Objekt', 'Details'];

    public function __construct(private readonly AuditLog $log) {}

    public function index(AuditLogFilterRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('audit/Index', [
            'entries' => $this->log->query($filters)->paginate(50)->withQueryString()
                ->through(fn (stdClass $row): array => $this->log->present($row)),
            'filters' => $filters,
            'areas' => AuditLog::AREAS,
            'retentionDays' => config('security.audit_retention_days'),
        ]);
    }

    public function export(AuditLogFilterRequest $request, ClubSettings $settings): StreamedResponse|HttpResponse
    {
        $filters = $request->filters();
        $format = (string) ($request->validated('format') ?? 'csv');
        $query = $this->log->query($filters);
        if ((clone $query)->count() > 20000) {
            throw ValidationException::withMessages(['scope' => 'Der Export ist auf 20.000 Einträge begrenzt. Bitte den Zeitraum oder die Filter einschränken.']);
        }
        $rows = [];
        foreach ($query->cursor() as $row) {
            $rows[] = array_values(array_diff_key($this->log->present($row), ['id' => true]));
        }
        $name = 'auditlog-'.Clock::localNow()->format('Y-m-d-His');
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        if ($format === 'pdf') {
            $club = $settings->data();
            $html = view('exports.list', [
                'title' => ($club['name'] ?? config('app.name')).' · Auditlog',
                'headers' => self::HEADERS, 'rows' => $rows, 'logo' => $settings->logoDataUri(),
                'printedAt' => Clock::localNow(), 'pdf' => true, 'countLabel' => 'Einträge',
            ])->render();

            return response(MemberReportWriter::pdf($html, true), 200, [...$headers, 'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"']);
        }
        if ($format === 'xlsx') {
            return response()->streamDownload(fn () => MemberReportWriter::excel(self::HEADERS, $rows), $name.'.xlsx', [
                ...$headers, 'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, self::HEADERS, ';', '"', '', "\r\n");
            foreach ($rows as $row) {
                fputcsv($output, array_map(fn (string $value): string => SafeCsv::value($value), $row), ';', '"', '', "\r\n");
            }
            fclose($output);
        }, $name.'.csv', [...$headers, 'Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
