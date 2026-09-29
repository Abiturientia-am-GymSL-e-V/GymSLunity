<?php

declare(strict_types=1);

namespace App\SelfService;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Support\Clock;
use App\Support\FormOfAddress;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/** Shared steps of the signed self-service submissions (inside their transaction). */
final class SubmissionGuard
{
    /** Lock the configuration and make sure the member signed the current form texts. */
    public static function lockConfiguration(int $version): ClubSetting
    {
        $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
        abort_unless($settings->data['selfservice_enabled'] ?? false, 404);
        if ($settings->version !== $version) {
            throw ValidationException::withMessages(['version' => FormOfAddress::choose('Die Formulartexte wurden geändert. Bitte lade das Formular neu und prüfe den aktuellen Text.', 'Die Formulartexte wurden geändert. Bitte laden Sie das Formular neu und prüfen Sie den aktuellen Text.')]);
        }

        return $settings;
    }

    /** @param array<string, mixed> $values */
    public static function lockMember(?Member $member, array $values): ?Member
    {
        if ($member === null) {
            return null;
        }
        $current = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
        PortalRules::assertUnchanged($current, (int) ($values['lock_version'] ?? -1));

        return $current;
    }

    /**
     * Account holder, IBAN and a fresh mandate reference.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function mandateDetails(Member $member, array $values): array
    {
        return [
            ...Arr::only($values, ['account_holder_first_name', 'account_holder_last_name', 'account_holder_street', 'account_holder_postal_code', 'account_holder_city', 'account_holder_country']),
            'iban' => $values['iban'],
            'mandate_reference' => 'M'.$member->member_number.'-'.strtoupper(bin2hex(random_bytes(6))),
            'mandate_signed_at' => Clock::todayString(),
        ];
    }
}
