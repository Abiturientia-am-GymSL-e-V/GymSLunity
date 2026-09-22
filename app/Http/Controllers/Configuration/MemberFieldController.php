<?php

namespace App\Http\Controllers\Configuration;

use App\Configuration\ConfigurationAudit;
use App\Http\Controllers\Controller;
use App\Members\MemberFields;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MemberFieldController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('configuration/MemberFields', [
            'fields' => MemberFieldDefinition::query()->orderBy('position')->orderBy('id')->get(),
            'sections' => MemberFields::SECTIONS, 'version' => ClubSetting::current()->fields_version,
            'types' => ['text' => 'Text', 'number' => 'Ganze Zahl', 'decimal' => 'Dezimalzahl', 'date' => 'Datum', 'boolean' => 'Ja / Nein', 'select' => 'Auswahl'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, MemberFieldDefinition $field): RedirectResponse
    {
        return $this->save($request, $field);
    }

    private function save(Request $request, ?MemberFieldDefinition $field = null): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'], 'label' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in($field && ! $field->is_custom ? [$field->type] : ['text', 'number', 'decimal', 'date', 'boolean', 'select'])],
            'section' => ['required', Rule::in(array_keys(MemberFields::SECTIONS))],
            'is_active' => ['required', 'boolean'], 'required' => ['required', 'boolean'],
            'filterable' => ['required', 'boolean'], 'show_in_table' => ['required', 'boolean'],
            'options' => ['present', 'array', 'max:100'],
            'options.*' => ['array:value,label,active'],
            'options.*.value' => ['required', 'string', 'max:'.($field->max_length ?? 255), 'distinct:strict', Rule::notIn(['__empty__', '__all__', '__any__', '__none__'])],
            'options.*.label' => ['required', 'string', 'max:120'], 'options.*.active' => ['required', 'boolean'],
            'remove_options' => ['sometimes', 'array', 'max:100'],
            'remove_options.*' => ['required', 'string', 'distinct'],
        ], ['required' => 'Dieses Feld ist erforderlich.', 'in' => 'Diese Auswahl ist nicht zulässig.', 'distinct' => 'Auswahlwerte müssen eindeutig sein.', 'max' => 'Der Wert ist zu lang oder die Liste zu groß.']);

        DB::transaction(function () use ($data, $request, $field): void {
            $settings = $this->lockedSettings((int) $data['version']);
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            $current = $field ? MemberFieldDefinition::query()->whereKey($field->getKey())->lockForUpdate()->firstOrFail() : new MemberFieldDefinition;
            $before = $current->exists ? $current->toArray() : [];
            if ($current->exists && in_array($current->key, ['first_name', 'last_name', 'membership_type'], true) && (! $data['is_active'] || ! $data['required'])) {
                throw ValidationException::withMessages(['is_active' => 'Vorname, Nachname und Mitgliedschaft bleiben aktive Pflichtfelder.']);
            }
            if ($current->exists && $data['type'] !== $current->type && $this->hasValues($current->key)) {
                throw ValidationException::withMessages(['type' => 'Für dieses Feld sind bereits Werte gespeichert. Bitte ein neues Feld mit dem gewünschten Datentyp anlegen.']);
            }
            $values = Arr::except($data, ['version', 'remove_options']);
            if ($data['type'] !== 'select') {
                $values['options'] = [];
            } elseif ($data['required'] && ! array_filter($data['options'], fn ($option) => $option['active'])) {
                throw ValidationException::withMessages(['options' => 'Ein Auswahl-Pflichtfeld benötigt mindestens eine aktive Option.']);
            }
            // Removed options remain as inactive labels for existing data and history.
            foreach ($current->options ?? [] as $option) {
                if ($data['type'] === 'select' && ! in_array($option['value'], array_column($values['options'], 'value'), true)) {
                    if (in_array($option['value'], $data['remove_options'] ?? [], true)) {
                        if ($this->optionIsInUse($current, $option['value'])) {
                            throw ValidationException::withMessages(['options' => 'Diese Option wird in Mitgliedern oder der Änderungshistorie verwendet. Bitte stattdessen deaktivieren.']);
                        }

                        continue;
                    }
                    $values['options'][] = [...$option, 'active' => false];
                }
            }
            if (! $current->exists) {
                if (MemberFieldDefinition::query()->count() >= 250) {
                    throw ValidationException::withMessages(['label' => 'Es können höchstens 250 Felder angelegt werden.']);
                }
                $current->key = 'custom_'.Str::lower(Str::random(16));
                $current->is_custom = true;
                $current->position = (int) MemberFieldDefinition::query()->max('position') + 10;
                $current->max_length = 255;
            }
            if (! $current->is_custom) {
                $values['filterable'] = false;
                $values['show_in_table'] = false;
            }
            $current->fill($values)->save();
            ConfigurationAudit::record($request->user(), 'Mitgliedsfeld: '.$current->key, $before, $current->toArray());
            $settings->increment('fields_version');
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Feldkonfiguration gespeichert.']);

        return to_route('configuration.fields.index');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'ids' => ['required', 'array', 'max:250'], 'ids.*' => ['required', 'integer', 'distinct']]);
        DB::transaction(function () use ($request, $data): void {
            $settings = $this->lockedSettings((int) $data['version']);
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            $before = MemberFieldDefinition::query()->orderBy('position')->orderBy('id')->pluck('id')->all();
            $ids = array_map(intval(...), $data['ids']);
            if (count($before) !== count($ids) || array_diff($before, $ids) !== []) {
                throw ValidationException::withMessages(['ids' => 'Die Feldliste hat sich geändert. Bitte lade die Seite neu.']);
            }
            foreach ($ids as $position => $id) {
                MemberFieldDefinition::query()->whereKey($id)->update(['position' => ($position + 1) * 10]);
            }
            ConfigurationAudit::record($request->user(), 'Reihenfolge der Mitgliedsfelder', ['ids' => $before], ['ids' => $ids]);
            $settings->increment('fields_version');
        });

        return to_route('configuration.fields.index');
    }

    private function lockedSettings(int $version): ClubSetting
    {
        $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
        if ($settings->fields_version !== $version) {
            throw ValidationException::withMessages(['version' => 'Die Konfiguration wurde inzwischen geändert. Bitte lade die Seite neu.']);
        }

        return $settings;
    }

    private function hasValues(string $key): bool
    {
        foreach (Member::query()->select('id', 'custom_values')->cursor() as $member) {
            $value = ($member->custom_values ?? [])[$key] ?? null;
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private function optionIsInUse(MemberFieldDefinition $field, string $value): bool
    {
        if (! $field->exists) {
            return false;
        }
        $memberQuery = Member::query();
        if ($field->is_custom) {
            $memberQuery->where('custom_values->'.$field->key, $value);
        } else {
            $memberQuery->where($field->key, $value);
        }

        return $memberQuery->exists()
            || \App\Models\MemberChange::query()->where('before->'.$field->key, $value)->orWhere('after->'.$field->key, $value)->exists();
    }
}
