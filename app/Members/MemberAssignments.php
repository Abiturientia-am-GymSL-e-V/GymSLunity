<?php

declare(strict_types=1);

namespace App\Members;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberChange;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use App\Support\Clock;
use App\Support\FormOfAddress;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Adds, ends, switches, corrects and deletes department, office and honor
 * assignments. Every action locks the member like UpdateMember, shares its
 * lock_version and writes one immutable member_changes entry whose before
 * and after hold the complete assignment list of the affected field.
 *
 * Duplicates and forbidden overlaps are rejected. Exceeding the maximum
 * number of office holders and assignments outside the membership only
 * produce warnings, which the actions return.
 */
final class MemberAssignments
{
    /**
     * @param  array{option?: mixed, starts_on?: mixed, ends_on?: mixed, note?: mixed}  $data
     * @return list<string> warnings
     */
    public function add(Member $member, User $actor, int $version, string $fieldKey, array $data, string $source = 'manual'): array
    {
        $data = $this->validated($data);

        return $this->mutate($member, $actor, $version, $fieldKey, function (MemberFieldDefinition $field) use ($member, $actor, $data, $source): array {
            $this->assertActiveOption($field, $data['option']);
            $assignment = MemberAssignment::query()->create([
                'member_id' => $member->getKey(), 'field_key' => $field->key, 'option_value' => $data['option'],
                'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'note' => $data['note'],
                'source' => $source, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);

            return [$assignment];
        });
    }

    /** @return list<string> warnings */
    public function end(Member $member, User $actor, int $version, MemberAssignment $assignment, string $endsOn): array
    {
        $endsOn = $this->validated(['option' => $assignment->option_value, 'ends_on' => $endsOn])['ends_on'];
        if ($endsOn === null) {
            throw ValidationException::withMessages(['ends_on' => 'Bitte ein Enddatum angeben.']);
        }

        return $this->mutate($member, $actor, $version, $assignment->field_key, function (MemberFieldDefinition $field, Collection $assignments) use ($assignment, $actor, $endsOn): array {
            $current = $this->owned($assignments, $assignment);
            if ($field->type === 'honor') {
                throw ValidationException::withMessages(['ends_on' => 'Ereignisse und Ehrungen haben kein Ende.']);
            }
            if ($current->ends_on !== null) {
                throw ValidationException::withMessages(['ends_on' => 'Diese Zuordnung ist bereits beendet. Bitte stattdessen korrigieren.']);
            }
            $current->update(['ends_on' => $endsOn, 'updated_by' => $actor->getKey()]);

            return [$current];
        });
    }

    /**
     * Ends an open assignment on $endsOn and continues with $option from the
     * next day, e.g. from the second to the first chair.
     *
     * @return list<string> warnings
     */
    public function switch(Member $member, User $actor, int $version, MemberAssignment $assignment, string $endsOn, string $option, ?string $note = null): array
    {
        $data = $this->validated(['option' => $option, 'ends_on' => $endsOn, 'note' => $note]);
        if ($data['ends_on'] === null) {
            throw ValidationException::withMessages(['ends_on' => 'Bitte angeben, wann die bisherige Zuordnung endet.']);
        }

        return $this->mutate($member, $actor, $version, $assignment->field_key, function (MemberFieldDefinition $field, Collection $assignments) use ($member, $assignment, $actor, $data): array {
            $current = $this->owned($assignments, $assignment);
            if ($field->type === 'honor') {
                throw ValidationException::withMessages(['option' => 'Ereignisse und Ehrungen können nicht gewechselt werden.']);
            }
            if ($current->ends_on !== null) {
                throw ValidationException::withMessages(['ends_on' => 'Nur laufende Zuordnungen können gewechselt werden.']);
            }
            if ($current->option_value === $data['option']) {
                throw ValidationException::withMessages(['option' => 'Bitte eine andere Auswahl als die bisherige treffen.']);
            }
            $this->assertActiveOption($field, $data['option']);
            $current->update(['ends_on' => $data['ends_on'], 'updated_by' => $actor->getKey()]);
            $next = MemberAssignment::query()->create([
                'member_id' => $member->getKey(), 'field_key' => $field->key, 'option_value' => $data['option'],
                'starts_on' => MemberAssignment::nextDay((string) $data['ends_on']), 'ends_on' => null, 'note' => $data['note'],
                'source' => 'manual', 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);

            return [$current, $next];
        });
    }

    /**
     * Fixes a recording error. The previous option stays allowed even if it
     * was deactivated in the meantime.
     *
     * @param  array{option?: mixed, starts_on?: mixed, ends_on?: mixed, note?: mixed}  $data
     * @return list<string> warnings
     */
    public function correct(Member $member, User $actor, int $version, MemberAssignment $assignment, array $data): array
    {
        $data = $this->validated($data);

        return $this->mutate($member, $actor, $version, $assignment->field_key, function (MemberFieldDefinition $field, Collection $assignments) use ($assignment, $actor, $data): array {
            $current = $this->owned($assignments, $assignment);
            if ($data['option'] !== $current->option_value) {
                $this->assertActiveOption($field, $data['option']);
            }
            $current->update([
                'option_value' => $data['option'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
                'note' => $data['note'], 'updated_by' => $actor->getKey(),
            ]);

            return [$current];
        });
    }

    /** Removes a wrongly recorded assignment; the member history keeps it. */
    public function delete(Member $member, User $actor, int $version, MemberAssignment $assignment): void
    {
        $this->mutate($member, $actor, $version, $assignment->field_key, function (MemberFieldDefinition $field, Collection $assignments) use ($assignment): array {
            $this->owned($assignments, $assignment)->delete();

            return [];
        });
    }

    /**
     * History representation of all assignments of one field.
     *
     * @param  iterable<MemberAssignment>  $assignments
     * @return list<array{option: string, starts_on: string|null, ends_on: string|null, note: string|null}>
     */
    public static function snapshot(iterable $assignments): array
    {
        $rows = [];
        foreach ($assignments as $assignment) {
            $rows[] = ['option' => $assignment->option_value, 'starts_on' => $assignment->startsOn(), 'ends_on' => $assignment->endsOn(), 'note' => $assignment->note];
        }
        usort($rows, fn (array $a, array $b): int => [$a['starts_on'] ?? '', $a['option'], $a['ends_on'] ?? '9999-12-31'] <=> [$b['starts_on'] ?? '', $b['option'], $b['ends_on'] ?? '9999-12-31']);

        return $rows;
    }

    /**
     * @param  Closure(MemberFieldDefinition, Collection<int, MemberAssignment>): list<MemberAssignment>  $change  returns the written assignments
     * @return list<string> warnings
     */
    private function mutate(Member $member, User $actor, int $version, string $fieldKey, Closure $change): array
    {
        return DB::transaction(function () use ($member, $actor, $version, $fieldKey, $change): array {
            ClubSetting::query()->whereKey(1)->sharedLock()->firstOrFail();
            abort_unless($actor->fresh()?->can('update', $member), 403);
            $current = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            if ($current->lock_version !== $version) {
                throw ValidationException::withMessages(['lock_version' => FormOfAddress::choose('Dieses Mitglied wurde inzwischen geändert. Deine Eingaben bleiben erhalten. Lade den aktuellen Stand, bevor du erneut bearbeitest.', 'Dieses Mitglied wurde inzwischen geändert. Ihre Eingaben bleiben erhalten. Laden Sie den aktuellen Stand, bevor Sie erneut bearbeiten.')]);
            }
            $field = MemberFieldDefinition::query()->where('key', $fieldKey)->first();
            if ($field === null || ! $field->isTemporal()) {
                throw ValidationException::withMessages(['form' => 'Dieses Feld unterstützt keine zeitlichen Zuordnungen.']);
            }
            if (! $field->is_active) {
                throw ValidationException::withMessages(['form' => FormOfAddress::choose('Dieses Feld wurde inzwischen deaktiviert. Bitte lade den aktuellen Stand.', 'Dieses Feld wurde inzwischen deaktiviert. Bitte laden Sie den aktuellen Stand.')]);
            }
            $load = fn (): Collection => MemberAssignment::query()->where('member_id', $current->getKey())->where('field_key', $field->key)->orderBy('id')->lockForUpdate()->get();
            $before = self::snapshot($load());

            $written = $change($field, $load());

            $assignments = $load();
            $warnings = [];
            foreach ($written as $assignment) {
                $assignment = $assignments->firstWhere('id', $assignment->getKey()) ?? $assignment;
                $this->assertAllowed($field, $assignment, $assignments);
                $warnings = [...$warnings, ...$this->warnings($field, $current, $assignment)];
            }
            $after = self::snapshot($assignments);
            if ($before === $after) {
                return [];
            }
            $current->lock_version = $version + 1;
            $current->save();
            MemberChange::query()->create([
                'member_id' => $current->getKey(), 'actor_id' => $actor->getKey(), 'actor_name' => $actor->name,
                'version' => $current->lock_version, 'before' => [$field->key => $before], 'after' => [$field->key => $after],
                'changed_fields' => [$field->key], 'created_at' => now(),
                'field_schema' => [$field->key => MemberFields::descriptor($field)],
            ]);
            $member->lock_version = $current->lock_version;

            return array_values(array_unique($warnings));
        }, attempts: 3);
    }

    /**
     * @param  array{option?: mixed, starts_on?: mixed, ends_on?: mixed, note?: mixed}  $data
     * @return array{option: string, starts_on: string|null, ends_on: string|null, note: string|null}
     */
    private function validated(array $data): array
    {
        $data = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $data);
        $valid = Validator::make($data, [
            'option' => ['required', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'option.required' => 'Bitte eine Auswahl treffen.',
            'date_format' => 'Bitte ein gültiges Datum eingeben.',
            'after_or_equal' => 'Das Ende darf nicht vor dem Beginn liegen.',
            'max' => 'Der Text ist zu lang (maximal :max Zeichen).',
            'string' => 'Bitte Text eingeben.',
        ])->validate();

        return [
            'option' => (string) $valid['option'], 'starts_on' => $valid['starts_on'] ?? null,
            'ends_on' => $valid['ends_on'] ?? null, 'note' => $valid['note'] ?? null,
        ];
    }

    /** @param Collection<int, MemberAssignment> $assignments */
    private function owned(Collection $assignments, MemberAssignment $assignment): MemberAssignment
    {
        $current = $assignments->firstWhere('id', $assignment->getKey());
        if ($current === null) {
            throw ValidationException::withMessages(['form' => FormOfAddress::choose('Diese Zuordnung existiert nicht mehr. Bitte lade den aktuellen Stand.', 'Diese Zuordnung existiert nicht mehr. Bitte laden Sie den aktuellen Stand.')]);
        }

        return $current;
    }

    private function assertActiveOption(MemberFieldDefinition $field, string $value): void
    {
        foreach ($field->options as $option) {
            if ($option['value'] === $value && $option['active']) {
                return;
            }
        }
        throw ValidationException::withMessages(['option' => 'Bitte eine gültige Auswahl für '.$field->label.' treffen.']);
    }

    /** @param Collection<int, MemberAssignment> $assignments */
    private function assertAllowed(MemberFieldDefinition $field, MemberAssignment $assignment, Collection $assignments): void
    {
        $label = $this->label($field, $assignment->option_value);
        if ($assignment->starts_on !== null && $assignment->ends_on !== null && $assignment->endsOn() < $assignment->startsOn()) {
            throw ValidationException::withMessages(['ends_on' => 'Das Ende darf nicht vor dem Beginn liegen.']);
        }
        if ($field->type === 'honor') {
            if ($assignment->ends_on !== null) {
                throw ValidationException::withMessages(['ends_on' => 'Ereignisse und Ehrungen haben kein Ende.']);
            }
            if ($assignment->starts_on !== null && $assignment->startsOn() > Clock::todayString()) {
                throw ValidationException::withMessages(['starts_on' => 'Das Datum einer Ehrung darf nicht in der Zukunft liegen.']);
            }
        }
        foreach ($assignments as $other) {
            if ($other->getKey() === $assignment->getKey()) {
                continue;
            }
            if ($other->option_value === $assignment->option_value) {
                $duplicate = $field->type === 'honor'
                    ? ! $this->option($field, $assignment->option_value, 'repeatable') || $other->startsOn() === $assignment->startsOn()
                    : MemberAssignment::periodsTouch($other->startsOn(), $other->endsOn(), $assignment->startsOn(), $assignment->endsOn());
                if ($duplicate) {
                    throw ValidationException::withMessages(['option' => $field->type === 'honor'
                        ? "„{$label}“ ist für dieses Mitglied bereits erfasst."
                        : "„{$label}“ ist für dieses Mitglied in diesem oder einem direkt angrenzenden Zeitraum bereits erfasst. Bitte die bestehende Zuordnung verlängern oder korrigieren."]);
                }
            } elseif ($field->type === 'office' && ! $field->allow_multiple
                && MemberAssignment::periodsOverlap($other->startsOn(), $other->endsOn(), $assignment->startsOn(), $assignment->endsOn())) {
                throw ValidationException::withMessages(['option' => "Im Feld „{$field->label}“ ist in diesem Zeitraum bereits „{$this->label($field, $other->option_value)}“ eingetragen. Bitte die bisherige Zuordnung beenden oder wechseln."]);
            }
        }
    }

    /** @return list<string> */
    private function warnings(MemberFieldDefinition $field, Member $member, MemberAssignment $assignment): array
    {
        $warnings = [];
        $label = $this->label($field, $assignment->option_value);
        $maxHolders = $this->option($field, $assignment->option_value, 'max_holders');
        if ($field->type === 'office' && is_int($maxHolders) && $maxHolders > 0) {
            $holders = $this->maxConcurrentHolders($field->key, $assignment->option_value, $assignment->startsOn(), $assignment->endsOn());
            if ($holders > $maxHolders) {
                $warnings[] = "„{$label}“ ist in diesem Zeitraum zeitweise mit {$holders} Personen besetzt, vorgesehen sind höchstens {$maxHolders}.";
            }
        }

        $joined = $member->joined_at?->toDateString();
        $left = collect([$member->left_at?->toDateString(), $member->deceased_at?->toDateString()])->filter()->min();
        $start = $assignment->startsOn();
        $end = $field->type === 'honor' ? $start : $assignment->endsOn();
        if ($joined === null
            || ($start !== null && $start < $joined)
            || ($left !== null && ($end === null || $end > $left))) {
            $warnings[] = "„{$label}“ liegt ganz oder teilweise außerhalb der Mitgliedschaft.";
        }

        return $warnings;
    }

    /** Highest number of simultaneous holders of an option within the period. */
    private function maxConcurrentHolders(string $fieldKey, string $option, ?string $from, ?string $to): int
    {
        $events = [];
        $query = MemberAssignment::query()->where('field_key', $fieldKey)->where('option_value', $option);
        foreach ($query->overlapping($from, $to)->get() as $holder) {
            $start = max($holder->startsOn() ?? '0000-01-01', $from ?? '0000-01-01');
            $end = min($holder->endsOn() ?? '9999-12-31', $to ?? '9999-12-31');
            $events[] = [$start, 1];
            // Periods are inclusive: a holder leaves at the start of the following day.
            if ($end !== '9999-12-31') {
                $events[] = [MemberAssignment::nextDay($end), -1];
            }
        }
        // On the same day, departures count before arrivals.
        usort($events, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $current = 0;
        $max = 0;
        foreach ($events as [, $delta]) {
            $current += $delta;
            $max = max($max, $current);
        }

        return $max;
    }

    private function option(MemberFieldDefinition $field, string $value, string $attribute): mixed
    {
        foreach ($field->options as $option) {
            if ($option['value'] === $value) {
                return $option[$attribute] ?? null;
            }
        }

        return null;
    }

    private function label(MemberFieldDefinition $field, string $value): string
    {
        foreach ($field->options as $option) {
            if ($option['value'] === $value) {
                return $option['label'];
            }
        }

        return $value;
    }
}
