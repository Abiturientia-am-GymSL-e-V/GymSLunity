<?php

namespace App\Members;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberChange;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use App\Support\FormOfAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class UpdateMember
{
    public function __construct(private readonly MemberMandates $mandates) {}

    /** @param array<string, mixed> $values */
    public function handle(Member $member, User $actor, int $version, array $values, ?int $configurationVersion = null): bool
    {
        if (array_diff(array_keys($values), MemberFields::writable()) !== []) {
            throw new InvalidArgumentException('Nicht bearbeitbare Mitgliedsfelder.');
        }

        return DB::transaction(function () use ($member, $actor, $version, $values, $configurationVersion): bool {
            $configuration = ClubSetting::query()->whereKey(1)->sharedLock()->firstOrFail();
            if ($configurationVersion !== null && $configuration->fields_version !== $configurationVersion) {
                throw ValidationException::withMessages(['form' => FormOfAddress::choose('Die Feldkonfiguration wurde inzwischen geändert. Bitte lade den aktuellen Stand; deine Eingaben bleiben bis dahin erhalten.', 'Die Feldkonfiguration wurde inzwischen geändert. Bitte laden Sie den aktuellen Stand; Ihre Eingaben bleiben bis dahin erhalten.')]);
            }
            abort_unless($actor->fresh()?->can('update', $member), 403);
            $current = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            if ($current->lock_version !== $version) {
                throw ValidationException::withMessages(['lock_version' => FormOfAddress::choose('Dieses Mitglied wurde inzwischen geändert. Deine Eingaben bleiben erhalten. Lade den aktuellen Stand, bevor du erneut bearbeitest.', 'Dieses Mitglied wurde inzwischen geändert. Ihre Eingaben bleiben erhalten. Laden Sie den aktuellen Stand, bevor Sie erneut bearbeiten.')]);
            }
            // Validate again while the field configuration and member are locked.
            Validator::make($values, MemberValidation::rules($current), MemberValidation::messages(), MemberValidation::attributes())->validate();
            if ($current->payment_method === 'SEPA-Lastschrift'
                && array_key_exists('payment_method', $values)
                && $values['payment_method'] !== 'SEPA-Lastschrift') {
                $this->mandates->revoke($current, 'Zahlungsart geändert zu '.($values['payment_method'] ?: 'nicht hinterlegt'));
                $values = [...$values, ...$this->mandates->clearedDetails()];
            }
            $before = MemberFields::snapshot($current);
            $definitions = MemberFieldDefinition::query()->whereIn('key', array_keys($values))->get();
            $custom = $current->custom_values ?? [];
            foreach ($definitions as $definition) {
                if (! $definition->is_active) {
                    throw ValidationException::withMessages(['form' => FormOfAddress::choose('Ein bearbeitetes Feld wurde inzwischen deaktiviert. Bitte lade den aktuellen Stand.', 'Ein bearbeitetes Feld wurde inzwischen deaktiviert. Bitte laden Sie den aktuellen Stand.')]);
                }
                $value = $values[$definition->key];
                if ($definition->is_custom) {
                    $custom[$definition->key] = $value === null ? null : match ($definition->type) {
                        'boolean' => (bool) $value, 'number' => (int) $value, 'decimal' => number_format((float) $value, 2, '.', ''), default => $value,
                    };
                } else {
                    $current->setAttribute($definition->key, $value);
                }
            }
            $current->custom_values = $custom;
            $after = MemberFields::snapshot($current);
            MemberValidation::validateDates($after);
            // Eloquent normalizes dates, decimals and booleans before comparison.
            $changed = array_values(array_filter(array_keys($values), fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null)));
            if ($changed === []) {
                return false;
            }
            $current->lock_version = $version + 1;
            $current->save();
            MemberChange::query()->create([
                'member_id' => $current->getKey(), 'actor_id' => $actor->getKey(), 'actor_name' => $actor->name,
                'version' => $current->lock_version, 'before' => $before, 'after' => $after,
                'changed_fields' => $changed, 'created_at' => now(),
                'field_schema' => $definitions->whereIn('key', $changed)->mapWithKeys(fn (MemberFieldDefinition $definition): array => [$definition->key => MemberFields::descriptor($definition)])->all(),
            ]);

            return true;
        }, attempts: 3);
    }
}
