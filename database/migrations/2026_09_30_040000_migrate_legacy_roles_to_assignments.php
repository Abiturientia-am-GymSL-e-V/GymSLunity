<?php

declare(strict_types=1);

use App\Members\MemberFields;
use App\Models\MemberFieldDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turns the former single-value fields "Funktion in der Abteilung"
 * (department_role) and "Funktion im Hauptverein" (club_role) into office
 * fields with time-bound assignments. The keys stay, so saved placeholders
 * and filters keep working and now stand for the current offices.
 *
 * Every stored value becomes an assignment with an unknown beginning. For
 * members who already left or died it ends on that day. Each migrated member
 * gets one history entry by "System (Migration)". The columns in members stay
 * untouched for one release as a fallback and are no longer written.
 *
 * Running it again changes nothing: fields that already are office fields
 * are skipped.
 */
return new class extends Migration
{
    private const KEYS = ['department_role', 'club_role'];

    private const ACTOR = 'System (Migration)';

    private const NOTE = 'Aus Altdaten übernommen';

    public function up(): void
    {
        DB::transaction(function (): void {
            $fields = DB::table('member_field_definitions')->whereIn('key', self::KEYS)->where('type', 'select')->lockForUpdate()->get()->keyBy('key');
            if ($fields->isEmpty()) {
                return;
            }
            $now = now();
            $before = [];
            $after = [];
            foreach ($fields as $key => $field) {
                $values = array_values(DB::table('members')->whereNotNull($key)->where($key, '<>', '')->distinct()->pluck($key)->map(fn ($value): string => (string) $value)->all());
                DB::table('member_field_definitions')->where('id', $field->id)->update([
                    'type' => 'office', 'section' => MemberFieldDefinition::ASSIGNMENT_SECTION, 'required' => false,
                    // Now an ordinary office field: configurable like any other and
                    // no longer bound to a column of members.
                    'is_custom' => true, 'filterable' => true, 'show_in_table' => true, 'selfservice_editable' => false,
                    'allow_multiple' => false, 'options' => json_encode($this->officeOptions((string) $field->options, $values), JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ]);

                $members = DB::table('members')->whereNotNull($key)->where($key, '<>', '')->orderBy('id')->get(['id', $key, 'left_at', 'deceased_at']);
                foreach ($members as $member) {
                    $option = (string) $member->{$key};
                    $endsOn = collect([$member->left_at, $member->deceased_at])->filter()->map(fn ($date): string => substr((string) $date, 0, 10))->min();
                    DB::table('member_assignments')->insert([
                        'member_id' => $member->id, 'field_key' => $key, 'option_value' => $option,
                        'starts_on' => null, 'ends_on' => $endsOn, 'note' => self::NOTE, 'source' => 'migration',
                        'created_by' => null, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $before[$member->id][$key] = $option;
                    $after[$member->id][$key] = [['option' => $option, 'starts_on' => null, 'ends_on' => $endsOn, 'note' => self::NOTE]];
                }
            }

            $schema = [];
            foreach (MemberFieldDefinition::query()->whereIn('key', $fields->keys())->get() as $definition) {
                $schema[$definition->key] = MemberFields::descriptor($definition);
            }
            foreach ($after as $memberId => $changes) {
                $version = (int) DB::table('members')->where('id', $memberId)->lockForUpdate()->value('lock_version') + 1;
                DB::table('members')->where('id', $memberId)->update(['lock_version' => $version]);
                DB::table('member_changes')->insert([
                    'member_id' => $memberId, 'actor_id' => null, 'actor_name' => self::ACTOR, 'version' => $version,
                    'before' => json_encode($before[$memberId], JSON_THROW_ON_ERROR), 'after' => json_encode($changes, JSON_THROW_ON_ERROR),
                    'changed_fields' => json_encode(array_keys($changes), JSON_THROW_ON_ERROR),
                    'field_schema' => json_encode(array_intersect_key($schema, $changes), JSON_THROW_ON_ERROR), 'created_at' => $now,
                ]);
            }
            // Configuration forms opened before the update must be reloaded. A new
            // installation has neither members nor open forms.
            if ($after !== [] || DB::table('members')->exists()) {
                DB::table('club_settings')->where('id', 1)->increment('fields_version');
            }
        });
    }

    /**
     * Restores the select fields as long as nobody recorded further
     * assignments for them. The members columns still hold the old values.
     * The history entries of the migration stay, the history is immutable.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $fields = DB::table('member_field_definitions')->whereIn('key', self::KEYS)->where('type', 'office')->lockForUpdate()->get();
            if ($fields->isEmpty()) {
                return;
            }
            $keys = $fields->pluck('key')->all();
            $changed = DB::table('member_assignments')->whereIn('field_key', $keys)
                ->where(fn ($query) => $query->where('source', '<>', 'migration')->orWhereColumn('updated_at', '<>', 'created_at'))
                ->exists();
            if ($changed) {
                throw new RuntimeException('Für „Funktion in der Abteilung“ oder „Funktion im Hauptverein“ wurden nach der Umstellung Zuordnungen erfasst oder geändert. Die Umstellung kann nicht automatisch zurückgenommen werden.');
            }
            DB::table('member_assignments')->whereIn('field_key', $keys)->delete();
            foreach ($fields as $field) {
                $options = array_map(
                    fn (array $option): array => ['value' => $option['value'], 'label' => $option['label'], 'active' => (bool) $option['active']],
                    json_decode((string) $field->options, true, flags: JSON_THROW_ON_ERROR),
                );
                DB::table('member_field_definitions')->where('id', $field->id)->update([
                    'type' => 'select', 'section' => 'roles', 'is_custom' => false, 'filterable' => false, 'show_in_table' => false,
                    'allow_multiple' => false, 'options' => json_encode($options, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
            DB::table('club_settings')->where('id', 1)->increment('fields_version');
        });
    }

    /**
     * Keeps value, label and state of each option. Values stored in members
     * without a matching option become inactive options, so their labels
     * survive. Whether an office belongs to the board is left to the club.
     *
     * @param  list<string>  $values
     * @return list<array{value: string, label: string, active: bool, board: bool, mandatory: bool, max_holders: null}>
     */
    private function officeOptions(string $json, array $values): array
    {
        $options = [];
        foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR) ?? [] as $option) {
            $options[] = ['value' => (string) $option['value'], 'label' => (string) $option['label'], 'active' => (bool) $option['active'], 'board' => false, 'mandatory' => false, 'max_holders' => null];
        }
        foreach (array_diff($values, array_column($options, 'value')) as $value) {
            $options[] = ['value' => $value, 'label' => $value, 'active' => false, 'board' => false, 'mandatory' => false, 'max_holders' => null];
        }

        return $options;
    }
};
