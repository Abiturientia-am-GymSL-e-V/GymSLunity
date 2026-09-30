<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Http\Controllers\Controller;
use App\Members\AssignmentCsvImport;
use App\Members\MemberFields;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV import of department, office and honor assignments with periods. */
class AssignmentImportController extends Controller
{
    public function index(Request $request, AssignmentCsvImport $import): Response
    {
        $validated = $request->validate(['token' => ['nullable', 'string', 'size:48', 'alpha_num']]);
        $preview = isset($validated['token']) ? $import->get($validated['token'], $request->user()) : null;

        return Inertia::render('members/ImportAssignments', [
            'totalMembers' => fn () => Member::query()->count(),
            'fields' => MemberFields::temporalFields(),
            'columns' => AssignmentCsvImport::COLUMNS,
            'preview' => $preview === null ? null : [
                'token' => $validated['token'], 'rows' => AssignmentCsvImport::rows($preview),
                'errorCount' => AssignmentCsvImport::errorCount(AssignmentCsvImport::rows($preview)),
            ],
        ]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            echo "\xEF\xBB\xBF".implode(';', AssignmentCsvImport::COLUMNS)."\r\n";
        }, 'zuordnungen-import-vorlage.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(Request $request, AssignmentCsvImport $import): RedirectResponse
    {
        $request->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], [
            'csv.required' => 'Bitte eine CSV-Datei auswählen.',
            'csv.mimes' => 'Bitte eine CSV-Datei hochladen.',
            'csv.max' => 'Die CSV-Datei darf höchstens 2 MB groß sein.',
        ]);

        return to_route('members.assignment-import.index', ['token' => $import->preview($request->file('csv'), $request->user())]);
    }

    public function store(Request $request, AssignmentCsvImport $import): RedirectResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'size:48', 'alpha_num']]);
        try {
            $count = $import->import($validated['token'], $request->user());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('members.assignment-import.index', ['token' => $validated['token']]));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 1 ? '1 Zuordnung wurde importiert.' : $count.' Zuordnungen wurden importiert.']);

        return to_route('members.assignment-import.index');
    }
}
