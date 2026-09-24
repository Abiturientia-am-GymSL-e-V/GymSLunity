<?php

namespace App\Http\Controllers\Configuration;

use App\Configuration\ClubData;
use App\Configuration\ConfigurationAudit;
use App\Http\Controllers\Controller;
use App\Models\ClubSetting;
use App\Rules\Iban;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClubController extends Controller
{
    public function edit(): Response
    {
        $settings = ClubSetting::current();

        return Inertia::render('configuration/Club', ['club' => $settings->data, 'version' => $settings->version, 'fields' => ClubData::fields()]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (is_string($request->input('iban'))) {
            $request->merge(['iban' => strtoupper(preg_replace('/\s+/', '', $request->input('iban')) ?? '') ?: null]);
        }
        $rules = ['version' => ['required', 'integer', 'min:0']];
        foreach (ClubData::fields() as $field) {
            $rules[$field['key']] = [$field['required'] ? 'required' : 'nullable', ...match ($field['type']) {
                'boolean' => ['boolean'], 'date' => ['date_format:Y-m-d'], 'email' => ['email:rfc', 'max:255'], 'url' => ['url:http,https', 'max:255'], default => ['string', 'max:255'],
            }];
        }
        $rules['iban'][] = new Iban;
        $data = $request->validate($rules, ['required' => ':attribute darf nicht leer sein.', 'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.', 'url' => 'Bitte eine vollständige URL mit https:// oder http:// eingeben.', 'max' => ':attribute ist zu lang.'], array_column(ClubData::fields(), 'label', 'key'));
        DB::transaction(function () use ($request, $data): void {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            if ($settings->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Die Vereinsdaten wurden inzwischen geändert. Bitte lade die Seite neu.']);
            }
            $clubKeys = array_column(ClubData::fields(), 'key');
            $values = [...Arr::except($settings->data, $clubKeys), ...Arr::except($data, 'version')];
            ConfigurationAudit::record($request->user(), 'Vereinsdaten', $settings->data, $values);
            $settings->update(['data' => $values, 'version' => $settings->version + 1]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vereinsdaten gespeichert.']);

        return to_route('configuration.club.edit');
    }
}
