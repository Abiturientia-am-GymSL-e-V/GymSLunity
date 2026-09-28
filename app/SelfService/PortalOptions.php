<?php

declare(strict_types=1);

namespace App\SelfService;

use App\Configuration\ClubSettings;
use App\Members\MemberFields;
use App\Models\MemberFieldDefinition;
use Illuminate\Support\Arr;

/** Choice lists offered to members in the portal and the join form. */
final class PortalOptions
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    /** @return array<string, string> */
    public function gender(): array
    {
        return $this->activeOptions('gender');
    }

    /** @return array<string, string> membership types a member can apply for */
    public function membership(): array
    {
        return Arr::except($this->activeOptions('membership_type'), 'Kontakt');
    }

    /**
     * Active payment methods; SEPA only while the club can collect it.
     *
     * @param  array<string, mixed>|null  $clubData  locked configuration inside a transaction
     * @return array<string, string>
     */
    public function payment(?array $clubData = null): array
    {
        $options = $this->activeOptions('payment_method');
        $missing = $clubData === null ? $this->clubSettings->missingSepaFields() : ClubSettings::missingSepaFieldsIn($clubData);
        if ($missing !== []) {
            unset($options['SEPA-Lastschrift']);
        }

        return $options;
    }

    public static function isSponsorMembership(string $membershipType): bool
    {
        return str_contains(mb_strtolower($membershipType), 'förder');
    }

    /** @return array<string, string> */
    private function activeOptions(string $key): array
    {
        $field = MemberFieldDefinition::query()->where('key', $key)->firstOrFail();

        return MemberFields::descriptor($field)['activeOptions'];
    }
}
