<?php

namespace App\SelfService;

use App\Members\MemberFields;
use App\Models\Member;
use App\Models\MemberChange;
use App\Models\MemberFieldDefinition;

final class ProfileChanges
{
    /**
     * Caller must hold the member row lock.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(Member $member, array $values): void
    {
        $before = MemberFields::snapshot($member);
        $member->fill($values);
        $after = MemberFields::snapshot($member);
        $changed = array_keys(array_filter($after, fn ($value, $key): bool => $value !== ($before[$key] ?? null), ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return;
        }
        $member->lock_version++;
        $member->save();
        MemberChange::query()->create([
            'member_id' => $member->id, 'actor_id' => null,
            'actor_name' => 'Selfservice: '.$member->first_name.' '.$member->last_name.' (#'.$member->member_number.')',
            'version' => $member->lock_version, 'before' => $before, 'after' => $after,
            'changed_fields' => $changed, 'created_at' => now(),
            'field_schema' => MemberFieldDefinition::query()->whereIn('key', $changed)->get()->mapWithKeys(fn (MemberFieldDefinition $field): array => [$field->key => MemberFields::descriptor($field)])->all(),
        ]);
    }
}
