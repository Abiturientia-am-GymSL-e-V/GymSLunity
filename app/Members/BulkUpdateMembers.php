<?php

namespace App\Members;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class BulkUpdateMembers
{
    public function __construct(private readonly UpdateMember $update) {}

    /**
     * @param  list<int>  $memberNumbers
     * @param  array<string, mixed>  $values
     * @return array{changed: int, unchanged: int}
     */
    public function handle(array $memberNumbers, array $values, User $actor, int $configurationVersion): array
    {
        if (array_diff(array_keys($values), MemberFields::writable()) !== []) {
            throw new InvalidArgumentException('Nicht bearbeitbare Mitgliedsfelder.');
        }

        return DB::transaction(function () use ($memberNumbers, $values, $actor, $configurationVersion): array {
            $configuration = ClubSetting::query()->whereKey(1)->sharedLock()->firstOrFail();
            if ($configuration->fields_version !== $configurationVersion) {
                throw ValidationException::withMessages(['form' => 'Die Mitgliederfelder wurden inzwischen geändert. Bitte die Liste neu laden.']);
            }
            abort_unless($actor->fresh()?->can('updateAny', Member::class), 403);
            $members = Member::query()->whereIn('member_number', $memberNumbers)->orderBy('id')->lockForUpdate()->get();
            if ($members->count() !== count($memberNumbers)) {
                throw ValidationException::withMessages(['members' => 'Mindestens ein ausgewähltes Mitglied ist nicht mehr vorhanden. Bitte die Liste neu laden.']);
            }

            $changed = 0;
            foreach ($members as $member) {
                try {
                    if ($this->update->handle($member, $actor, $member->lock_version, $values, $configurationVersion)) {
                        $changed++;
                    }
                } catch (ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $key => $messages) {
                        $errors[in_array($key, ['form', 'members'], true) ? $key : 'values.'.$key] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
            }

            return ['changed' => $changed, 'unchanged' => $members->count() - $changed];
        }, attempts: 3);
    }
}
