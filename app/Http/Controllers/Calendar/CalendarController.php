<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Calendar\CalendarEntries;
use App\Http\Controllers\Controller;
use App\Models\ClubCalendar;
use App\Models\ClubCalendarRule;
use App\Models\MemberFieldDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(Request $request, CalendarEntries $entries): Response
    {
        $month = $request->string('month')->toString();
        try {
            $focus = $month !== '' ? CarbonImmutable::createFromFormat('!Y-m', $month, config('app.timezone')) : CarbonImmutable::today()->startOfMonth();
        } catch (\Throwable) {
            $focus = CarbonImmutable::today()->startOfMonth();
        }
        $from = $focus->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);
        $until = $focus->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->addDay();
        $calendars = ClubCalendar::query()->with('rules')->orderBy('id')->get();

        return Inertia::render('Calendar', [
            'month' => $focus->format('Y-m'),
            'calendars' => $calendars->map(fn (ClubCalendar $calendar): array => $this->calendarRow($calendar))->values(),
            'events' => $entries->between($calendars, $from, $until),
            'shareFields' => $this->shareFields(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        ClubCalendar::query()->create([...$data, 'type' => 'custom']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Kalender wurde angelegt.']);

        return back();
    }

    public function update(Request $request, ClubCalendar $calendar): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $calendar->update($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Kalender wurde gespeichert.']);

        return back();
    }

    public function destroy(ClubCalendar $calendar): RedirectResponse
    {
        abort_unless($calendar->type === 'custom', 422, 'Standardkalender können nicht gelöscht werden.');
        $calendar->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Kalender und seine Termine wurden gelöscht.']);

        return back();
    }

    public function updateRules(Request $request, ClubCalendar $calendar): RedirectResponse
    {
        $fields = collect($this->shareFields())->keyBy('key');
        $data = $request->validate([
            'rules' => ['array', 'max:50'], 'rules.*.field_key' => ['required', 'string', Rule::in($fields->keys())], 'rules.*.value' => ['required', 'string', 'max:255'],
        ]);
        foreach ($data['rules'] ?? [] as $index => $rule) {
            $allowed = collect($fields[$rule['field_key']]['options'])->pluck('value');
            if (! $allowed->containsStrict($rule['value'])) {
                return back()->withErrors(["rules.$index.value" => 'Dieser Feldwert ist nicht verfügbar.']);
            }
        }
        $rules = [];
        foreach ($data['rules'] ?? [] as $rule) {
            $key = $rule['field_key']."\0".$rule['value'];
            $rules[$key] = ['field_key' => $rule['field_key'], 'value' => $rule['value']];
        }
        DB::transaction(function () use ($calendar, $rules): void {
            $calendar->rules()->delete();
            $calendar->rules()->createMany(array_values($rules));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Die Freigaben wurden gespeichert.']);

        return back();
    }

    public function enablePublicLink(ClubCalendar $calendar): RedirectResponse
    {
        $calendar->update(['public_token' => bin2hex(random_bytes(24))]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ein neuer öffentlicher Abo-Link wurde erzeugt.']);

        return back();
    }

    public function disablePublicLink(ClubCalendar $calendar): RedirectResponse
    {
        $calendar->update(['public_token' => null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der öffentliche Abo-Link wurde deaktiviert.']);

        return back();
    }

    /** @return list<array{key: string, label: string, options: list<array{value: string, label: string}>}> */
    private function shareFields(): array
    {
        $result = [];
        foreach (MemberFieldDefinition::query()->where('is_active', true)->whereIn('type', ['select', 'boolean'])->orderBy('position')->get() as $field) {
            $options = $field->type === 'boolean'
                ? [['value' => '1', 'label' => 'Ja'], ['value' => '0', 'label' => 'Nein']]
                : array_values(collect($field->options)->filter(fn (array $option): bool => $option['active'])->map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']])->all());
            if ($options !== []) {
                $result[] = ['key' => $field->key, 'label' => $field->label, 'options' => $options];
            }
        }

        $result[] = [
            'key' => '*',
            'label' => 'Alle Mitglieder',
            'options' => [['value' => '*', 'label' => 'Alle']],
        ];

        return $result;
    }

    /** @return array<string, mixed> */
    private function calendarRow(ClubCalendar $calendar): array
    {
        return [
            'id' => $calendar->id, 'name' => $calendar->name, 'color' => $calendar->color, 'type' => $calendar->type,
            'public_url' => $calendar->public_token ? route('calendar.feed.public', $calendar->public_token) : null,
            'rules' => $calendar->rules->map(fn (ClubCalendarRule $rule): array => ['id' => $rule->id, 'field_key' => $rule->field_key, 'value' => $rule->value])->values(),
        ];
    }
}
