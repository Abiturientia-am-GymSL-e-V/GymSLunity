<?php

declare(strict_types=1);

namespace App\Members;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or ends one department, office or honor assignment for several
 * members at once, e.g. an honor for all members with a jubilee. Every
 * member gets its own history entry through MemberAssignments. The action
 * applies to all selected members or, on the first conflict, to none.
 */
final class BulkAssignments
{
    public function __construct(private readonly MemberAssignments $assignments) {}

    /**
     * @param  list<int>  $memberNumbers
     * @param  array{option: string, starts_on: string|null, ends_on: string|null, note: string|null}  $data
     * @return array{changed: int, unchanged: int, warnings: list<string>}
     */
    public function add(array $memberNumbers, User $actor, int $configurationVersion, string $fieldKey, array $data): array
    {
        return $this->run($memberNumbers, $actor, $configurationVersion, $fieldKey, fn (Member $member): array => $this->assignments->add($member, $actor, $member->lock_version, $fieldKey, $data));
    }

    /**
     * Ends the open assignments valid on $endsOn, optionally only those of
     * one option. Members without such an assignment stay unchanged.
     *
     * @param  list<int>  $memberNumbers
     * @return array{changed: int, unchanged: int, warnings: list<string>}
     */
    public function end(array $memberNumbers, User $actor, int $configurationVersion, string $fieldKey, ?string $option, string $endsOn): array
    {
        if (MemberFieldDefinition::query()->where('key', $fieldKey)->value('type') === 'honor') {
            throw ValidationException::withMessages(['field' => 'Ereignisse und Ehrungen haben kein Ende.']);
        }

        return $this->run($memberNumbers, $actor, $configurationVersion, $fieldKey, function (Member $member, MemberFieldDefinition $field) use ($actor, $option, $endsOn): ?array {
            $open = MemberAssignment::query()->where('member_id', $member->getKey())->where('field_key', $field->key)
                ->whereNull('ends_on')->activeOn($endsOn)
                ->when($option !== null, fn ($query) => $query->where('option_value', $option))
                ->orderBy('id')->get();
            if ($open->isEmpty()) {
                return null;
            }
            $warnings = [];
            foreach ($open as $assignment) {
                $warnings = [...$warnings, ...$this->assignments->end($member, $actor, $member->lock_version, $assignment, $endsOn)];
            }

            return $warnings;
        });
    }

    /**
     * @param  list<int>  $memberNumbers
     * @param  Closure(Member, MemberFieldDefinition): (list<string>|null)  $change  returns warnings, or null when nothing changed
     * @return array{changed: int, unchanged: int, warnings: list<string>}
     */
    private function run(array $memberNumbers, User $actor, int $configurationVersion, string $fieldKey, Closure $change): array
    {
        return DB::transaction(function () use ($memberNumbers, $actor, $configurationVersion, $fieldKey, $change): array {
            $configuration = ClubSetting::query()->whereKey(1)->sharedLock()->firstOrFail();
            if ($configuration->fields_version !== $configurationVersion) {
                throw ValidationException::withMessages(['form' => 'Die Mitgliederfelder wurden inzwischen geändert. Bitte die Liste neu laden.']);
            }
            abort_unless($actor->fresh()?->can('manage-assignments'), 403);
            $field = MemberFieldDefinition::query()->where('key', $fieldKey)->where('is_active', true)->first();
            if ($field === null || ! $field->isTemporal()) {
                throw ValidationException::withMessages(['field' => 'Bitte ein aktives Feld für Abteilungen, Ämter oder Ehrungen wählen.']);
            }
            $members = Member::query()->whereIn('member_number', $memberNumbers)->orderBy('id')->lockForUpdate()->get();
            if ($members->count() !== count($memberNumbers)) {
                throw ValidationException::withMessages(['members' => 'Mindestens ein ausgewähltes Mitglied ist nicht mehr vorhanden. Bitte die Liste neu laden.']);
            }

            $changed = 0;
            $warnings = [];
            foreach ($members as $member) {
                try {
                    $result = $change($member, $field);
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();
                    throw ValidationException::withMessages(['members' => AssignmentReports::name($member).' (Nr. '.$member->member_number.'): '.$message]);
                }
                if ($result !== null) {
                    $changed++;
                    foreach (array_unique($result) as $warning) {
                        $warnings[$warning] = ($warnings[$warning] ?? 0) + 1;
                    }
                }
            }

            return [
                'changed' => $changed, 'unchanged' => $members->count() - $changed,
                'warnings' => array_map(
                    fn (string $warning, int $count): string => $count > 1 ? rtrim($warning, '.')." ({$count} Mitglieder)." : $warning,
                    array_keys($warnings), array_values($warnings),
                ),
            ];
        }, attempts: 3);
    }
}
