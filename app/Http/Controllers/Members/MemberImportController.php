<?php

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Http\Requests\Members\PreviewMemberImportRequest;
use App\Members\CreateMember;
use App\Members\MemberCsvImport;
use App\Members\MemberFields;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberImportController extends Controller
{
    public function index(Request $request, MemberCsvImport $import): Response
    {
        Gate::authorize('create', Member::class);
        $validated = $request->validate(['token' => ['nullable', 'string', 'size:48', 'alpha_num']]);
        $preview = isset($validated['token']) ? $import->get($validated['token'], $request->user()) : null;
        $samples = [];
        if ($preview !== null && is_array($preview['source_rows'] ?? null)) {
            foreach (array_slice($preview['source_rows'], 0, 5) as $row) {
                if (is_array($row)) {
                    $samples[] = [
                        'line' => $row['line'],
                        'values' => $row['values'] ?? [],
                        'error' => $row['structural_error'] ?? null,
                    ];
                }
            }
        }
        $mapping = $preview !== null && ($preview['stage'] ?? null) === 'mapping' ? [
            'token' => $validated['token'],
            'headers' => $preview['source_headers'],
            'suggested' => $preview['mapping'],
            'required' => $import->requiredColumns(),
            'delimiter' => match ($preview['delimiter']) {
                ',' => 'Komma', "\t" => 'Tabulator', default => 'Semikolon',
            },
            'samples' => $samples,
        ] : null;

        return Inertia::render('members/Import', [
            'fields' => MemberFields::directoryFields(),
            'mapping' => $mapping,
            'preview' => $preview === null || ($preview['stage'] ?? null) !== 'preview' ? null : [
                'token' => $validated['token'], 'rows' => $preview['rows'],
                'errorCount' => $preview['error_count'], 'mapped' => (bool) ($preview['manual_mapping'] ?? false),
            ],
        ]);
    }

    public function template(MemberCsvImport $import): StreamedResponse
    {
        Gate::authorize('create', Member::class);

        return response()->streamDownload(function () use ($import): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Die CSV-Ausgabe konnte nicht geöffnet werden.');
            }
            try {
                echo "\xEF\xBB\xBF";
                if (fputcsv($output, $import->columns(), ';', '"', '', "\r\n") === false) {
                    throw new \RuntimeException('Die CSV-Vorlage konnte nicht geschrieben werden.');
                }
            } finally {
                fclose($output);
            }
        }, 'mitglieder-import-vorlage.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(PreviewMemberImportRequest $request, MemberCsvImport $import): RedirectResponse
    {
        $preview = $import->preview($request->file('csv'), $request->user());

        return to_route('members.import.index', ['token' => $preview['token']]);
    }

    public function map(Request $request, MemberCsvImport $import): RedirectResponse
    {
        Gate::authorize('create', Member::class);
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:48', 'alpha_num'],
            'mapping' => ['required', 'array', 'max:200'],
            'mapping.*.source' => ['required', 'string', 'max:255'],
            'mapping.*.target' => ['nullable', 'string', 'max:80'],
        ]);
        $import->mapColumns($validated['token'], $request->user(), $validated['mapping']);

        return to_route('members.import.index', ['token' => $validated['token']]);
    }

    public function store(Request $request, MemberCsvImport $import, CreateMember $create): RedirectResponse
    {
        Gate::authorize('create', Member::class);
        $validated = $request->validate(['token' => ['required', 'string', 'size:48', 'alpha_num']]);
        try {
            $count = $import->import($validated['token'], $request->user(), $create);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('members.import.index', ['token' => $validated['token']]));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $count.' Mitglieder wurden importiert.']);

        return to_route('members.index');
    }
}
