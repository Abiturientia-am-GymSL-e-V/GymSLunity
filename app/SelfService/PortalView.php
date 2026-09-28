<?php

declare(strict_types=1);

namespace App\SelfService;

use App\Configuration\ClubSettings;
use App\Members\MemberFields;
use App\Models\ContributionAccount;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Payments\GiroCode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/** Read model for the member portal. */
final class PortalView
{
    public function __construct(
        private readonly ClubSettings $clubSettings,
        private readonly GiroCode $giroCode,
    ) {}

    /**
     * Fields the member may change themselves. Membership, contribution and
     * payment details stay locked for anyone without an active membership.
     *
     * @return Collection<int, MemberFieldDefinition>
     */
    public function editableDefinitions(Member $member): Collection
    {
        return MemberFieldDefinition::query()
            ->where('is_active', true)
            ->where('selfservice_visible', true)
            ->where('selfservice_editable', true)
            ->whereNotIn('key', MemberFields::SELFSERVICE_PROTECTED)
            ->when(! $member->hasActiveOrUpcomingMembership(), fn ($query) => $query->whereNotIn('key', ['membership_type', 'sponsor_contribution', 'payment_method']))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** @return list<array{key: string, title: string, fields: list<array<string, mixed>>}> */
    public function profileSections(Member $member): array
    {
        $editableKeys = $this->editableDefinitions($member)->pluck('key')->all();

        return array_values(collect(MemberFields::selfserviceSections($member))
            ->map(fn (array $section): array => [
                ...$section,
                'fields' => array_map(
                    function (array $field) use ($member, $editableKeys): array {
                        $field['readOnly'] = $field['readOnly'] || ! in_array($field['key'], $editableKeys, true);
                        if ($field['key'] === 'payment_method' && $member->payment_method !== 'SEPA-Lastschrift') {
                            $field['activeOptions'] = Arr::except($field['activeOptions'], 'SEPA-Lastschrift');
                        }

                        return $field;
                    },
                    $section['fields'],
                ),
            ])
            ->filter(fn (array $section): bool => $section['fields'] !== [])
            ->values()
            ->all());
    }

    /** @return array<string, mixed> balance, recent bookings and a GiroCode for open transfers */
    public function contributionAccount(Member $member): array
    {
        $account = ContributionAccount::query()->firstOrCreate(['member_id' => $member->id], ['balance_cents' => 0]);
        $giro = null;
        $holder = $this->clubSettings->text('account_holder');
        $iban = $this->clubSettings->text('iban');
        if ($account->balance_cents > 0 && $member->payment_method === 'Überweisung' && $holder !== '' && $iban !== '') {
            $bic = $this->clubSettings->get('bic');
            $giro = $this->giroCode->create($account->balance_cents, $holder, $iban, is_string($bic) ? $bic : null, $member->member_number);
        }

        return [
            'balance_cents' => $account->balance_cents,
            'giroCode' => $giro,
            'transactions' => $account->transactions()->latest('booking_date')->latest('id')->limit(25)->get()
                ->map(fn ($entry): array => [
                    'id' => $entry->id,
                    'amount_cents' => $entry->amount_cents,
                    'booking_date' => $entry->booking_date->format('Y-m-d'),
                    'description' => $entry->description,
                    'reference' => $entry->reference,
                ]),
        ];
    }
}
