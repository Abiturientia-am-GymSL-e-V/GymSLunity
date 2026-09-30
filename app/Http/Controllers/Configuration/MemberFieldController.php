<?php

declare(strict_types=1);

namespace App\Http\Controllers\Configuration;

use App\Configuration\ClubSettings;
use App\Configuration\ConfigurationAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\MemberFieldRequest;
use App\Members\MemberFields;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberChange;
use App\Models\MemberFieldDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MemberFieldController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function index(): Response
    {
        return Inertia::render('configuration/MemberFields', [
            'fields' => MemberFieldDefinition::query()->orderBy('position')->orderBy('id')->get(),
            'sections' => [...MemberFields::SECTIONS, MemberFieldDefinition::ASSIGNMENT_SECTION => 'Abteilungen, Ämter & Ehrungen'],
            'version' => $this->clubSettings->fieldsVersion(),
            'types' => [
                'text' => 'Text', 'number' => 'Ganze Zahl', 'decimal' => 'Dezimalzahl', 'date' => 'Datum', 'boolean' => 'Ja / Nein', 'select' => 'Auswahl',
                'department' => 'Abteilung (mit Zeitraum)', 'office' => 'Funktion / Amt (mit Zeitraum)', 'honor' => 'Ereignis / Ehrung (mit Datum)',
            ],
        ]);
    }

    public function store(MemberFieldRequest $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(MemberFieldRequest $request, MemberFieldDefinition $field): RedirectResponse
    {
        return $this->save($request, $field);
    }

    private function save(MemberFieldRequest $request, ?MemberFieldDefinition $field = null): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $field): void {
            $settings = $this->lockedSettings((int) $data['version']);
            abort_unless($request->user()->fresh()?->isAdministrator(), 403);
            $current = $field ? MemberFieldDefinition::query()->whereKey($field->getKey())->lockForUpdate()->firstOrFail() : new MemberFieldDefinition;
            $before = $current->exists ? $current->toArray() : [];
            if ($current->exists && in_array($current->key, ['first_name', 'last_name', 'membership_type'], true) && (! $data['is_active'] || ! $data['required'])) {
                throw ValidationException::withMessages(['is_active' => 'Vorname, Nachname und Mitgliedschaft bleiben aktive Pflichtfelder.']);
            }
            if ($current->exists && $data['type'] !== $current->type && $this->hasValues($current)) {
                throw ValidationException::withMessages(['type' => 'Für dieses Feld sind bereits Werte gespeichert. Bitte ein neues Feld mit dem gewünschten Datentyp anlegen.']);
            }
            $temporal = in_array($data['type'], MemberFieldDefinition::TEMPORAL_TYPES, true);
            if (! $temporal && $data['section'] === MemberFieldDefinition::ASSIGNMENT_SECTION) {
                throw ValidationException::withMessages(['section' => 'Dieser Abschnitt ist Abteilungen, Ämtern und Ehrungen vorbehalten.']);
            }
            // Temporal fields are not part of the member form, filters or the portal (yet).
            $selfserviceVisible = ! $temporal && (bool) ($data['selfservice_visible'] ?? $current->selfservice_visible ?? $current->selfservice_editable ?? false);
            $selfserviceEditable = ! $temporal && (bool) ($data['selfservice_editable'] ?? $current->selfservice_editable ?? false);
            if ($selfserviceEditable && ! $selfserviceVisible) {
                throw ValidationException::withMessages(['selfservice_editable' => 'Ein im Mitgliederportal änderbares Feld muss dort auch angezeigt werden.']);
            }
            if ($selfserviceEditable && in_array($current->key, MemberFields::SELFSERVICE_PROTECTED, true)) {
                throw ValidationException::withMessages(['selfservice_editable' => 'Dieses Feld wird aus Sicherheits- oder Nachweisgründen ausschließlich über den vorgesehenen Verwaltungs- bzw. Bestätigungsweg geändert.']);
            }
            $values = Arr::except($data, ['version', 'remove_options']);
            $values['selfservice_visible'] = $selfserviceVisible;
            $values['selfservice_editable'] = $selfserviceEditable;
            $values['allow_multiple'] = $data['type'] === 'office' && (bool) ($data['allow_multiple'] ?? false);
            if ($temporal) {
                $values = [...$values, 'section' => MemberFieldDefinition::ASSIGNMENT_SECTION, 'required' => false, 'filterable' => false, 'show_in_table' => false];
            }
            $values['options'] = $data['type'] === 'select' || $temporal
                ? array_map(fn (array $option): array => $this->option($data['type'], $option), $data['options'])
                : [];
            if ($data['type'] === 'select' && $data['required'] && ! array_filter($data['options'], fn ($option) => $option['active'])) {
                throw ValidationException::withMessages(['options' => 'Ein Auswahl-Pflichtfeld benötigt mindestens eine aktive Option.']);
            }
            // Removed options remain as inactive labels for existing data and history.
            foreach ($current->options ?? [] as $option) {
                if (($data['type'] === 'select' || $temporal) && ! in_array($option['value'], array_column($values['options'], 'value'), true)) {
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
                throw ValidationException::withMessages(['ids' => 'Die Feldliste hat sich geändert. Bitte die Seite neu laden.']);
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
            throw ValidationException::withMessages(['version' => 'Die Konfiguration wurde inzwischen geändert. Bitte die Seite neu laden.']);
        }

        return $settings;
    }

    /**
     * Only the attributes meaningful for the field type are kept.
     *
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    private function option(string $type, array $option): array
    {
        $base = ['value' => $option['value'], 'label' => $option['label'], 'active' => (bool) $option['active']];

        return match ($type) {
            'office' => [...$base, 'board' => (bool) ($option['board'] ?? false), 'mandatory' => (bool) ($option['mandatory'] ?? false),
                'max_holders' => isset($option['max_holders']) ? (int) $option['max_holders'] : null],
            'honor' => [...$base, 'repeatable' => (bool) ($option['repeatable'] ?? false)],
            default => $base,
        };
    }

    private function hasValues(MemberFieldDefinition $field): bool
    {
        if ($field->isTemporal()) {
            return MemberAssignment::query()->where('field_key', $field->key)->exists();
        }
        $key = $field->key;
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
        if ($field->isTemporal()) {
            if (MemberAssignment::query()->where('field_key', $field->key)->where('option_value', $value)->exists()) {
                return true;
            }
            $history = MemberChange::query()->where(fn ($query) => $query->whereNotNull('before->'.$field->key)->orWhereNotNull('after->'.$field->key));
            foreach ($history->cursor() as $change) {
                foreach ([$change->before[$field->key] ?? [], $change->after[$field->key] ?? []] as $rows) {
                    if (is_array($rows) && in_array($value, array_column($rows, 'option'), true)) {
                        return true;
                    }
                }
            }

            return false;
        }
        $memberQuery = Member::query();
        if ($field->is_custom) {
            $memberQuery->where('custom_values->'.$field->key, $value);
        } else {
            $memberQuery->where($field->key, $value);
        }

        return $memberQuery->exists()
            || MemberChange::query()->where('before->'.$field->key, $value)->orWhere('after->'.$field->key, $value)->exists();
    }
}
