<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Members\MemberFields;
use App\Members\MemberReportValue;
use App\Members\MemberReportWriter;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberChange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MemberCardController extends Controller
{
    public function __invoke(Request $request, Member $member): Response
    {
        Gate::authorize('view', $member);
        $data = $request->validate(['format' => ['nullable', Rule::in(['print', 'pdf'])]]);
        $format = $data['format'] ?? 'print';
        $timezone = config('app.display_timezone');
        $snapshot = MemberFields::snapshot($member);
        $sections = [];
        $defined = [];
        foreach (MemberFields::sections($member) as $section) {
            $rows = [];
            foreach ($section['fields'] as $field) {
                $defined[] = $field['key'];
                $rows[] = ['label' => $field['label'], 'value' => MemberReportValue::format($snapshot[$field['key']] ?? null, $field)];
            }
            $sections[] = ['title' => $section['title'], 'rows' => $rows];
        }
        $unknown = array_diff(array_keys($snapshot), $defined);
        if ($unknown !== []) {
            $sections[] = ['title' => 'Weitere gespeicherte Angaben', 'rows' => array_map(fn (string $key): array => ['label' => $key, 'value' => MemberReportValue::format($snapshot[$key])], $unknown)];
        }
        $documents = DB::table('member_documents')->where('member_id', $member->getKey())->whereIn('kind', ['application', 'sepa'])->get(['id', 'kind', 'submitted_online', 'mandate_reference', 'revoked_at', 'created_at'])->map(function (object $document) use ($timezone): object {
            $document->created_at = CarbonImmutable::parse($document->created_at, config('app.timezone'))->setTimezone($timezone)->format('d.m.Y H:i T');

            return $document;
        });
        $history = MemberChange::query()->where('member_id', $member->getKey())->orderBy('version')->get();
        $labels = collect(MemberFields::directoryFields())->keyBy('key');
        $changes = $history->map(function (MemberChange $change) use ($labels, $timezone): array {
            $rows = [];
            foreach ($change->changed_fields as $key) {
                $field = $change->field_schema[$key] ?? $labels[$key] ?? ['key' => $key, 'label' => $key];
                $rows[] = [
                    'label' => $field['label'],
                    'before' => MemberReportValue::format($change->before[$key] ?? null, $field),
                    'after' => MemberReportValue::format($change->after[$key] ?? null, $field),
                ];
            }

            return ['actor' => $change->actor_name, 'date' => $change->created_at->setTimezone($timezone)->format('d.m.Y H:i T'), 'version' => $change->version, 'rows' => $rows];
        });
        $settings = ClubSetting::current();
        $club = $settings->data;
        $logo = $settings->logoDataUri();
        $printedAt = now()->setTimezone($timezone);
        $html = view('exports.card', compact('member', 'sections', 'documents', 'changes', 'club', 'logo', 'printedAt', 'timezone') + ['pdf' => $format === 'pdf'])->render();
        if ($format === 'pdf') {
            return response(MemberReportWriter::pdf($html), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="karteiblatt-'.$member->member_number.'.pdf"',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'",
        ]);
    }
}
