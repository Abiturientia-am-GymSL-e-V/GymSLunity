<?php

declare(strict_types=1);

namespace App\SelfService;

use App\Http\Requests\SelfService\MembershipApplicationRequest;
use App\Members\MemberMandates;
use App\Models\Member;
use App\Support\FormOfAddress;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitMembershipApplication
{
    public function __construct(
        private readonly Documents $documents,
        private readonly ProfileChanges $changes,
        private readonly MemberMandates $mandates,
        private readonly PortalOptions $options,
        private readonly PortalRules $rules,
    ) {}

    /** @return array{member: Member, applicationPdf: string} */
    public function handle(MembershipApplicationRequest $request): array
    {
        $values = $request->validated();
        $email = $request->email();
        $member = $request->member();

        return DB::transaction(function () use ($request, $values, $email, $member): array {
            // Serialize public allocations and configuration changes, then lock the member.
            $settings = SubmissionGuard::lockConfiguration((int) $values['version']);
            $current = SubmissionGuard::lockMember($member, $values);
            abort_unless($this->rules->mayJoin($current), 403);
            if (! array_key_exists($values['payment_method'], $this->options->payment($settings->data))) {
                throw ValidationException::withMessages(['payment_method' => FormOfAddress::choose('Diese Zahlungsart ist nicht mehr verfügbar. Bitte lade das Formular neu.', 'Diese Zahlungsart ist nicht mehr verfügbar. Bitte laden Sie das Formular neu.')]);
            }
            $requiresApproval = ($settings->data['membership_activation'] ?? 'immediate') === 'approval';
            $updates = [
                ...Arr::only($values, MembershipApplicationRequest::PROFILE),
                'payment_method' => $values['payment_method'],
                'sponsor_contribution' => PortalOptions::isSponsorMembership($values['membership_type']) ? number_format((float) $values['sponsor_contribution'], 2, '.', '') : null,
                'membership_type' => $requiresApproval ? 'Kontakt' : $values['membership_type'],
                'joined_at' => $requiresApproval ? null : now()->toDateString(),
                'left_at' => null,
            ];
            if (! $current) {
                if (Member::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages(['accepted' => FormOfAddress::choose('Zu dieser Adresse gibt es bereits einen Datensatz. Bitte fordere einen Mitgliederzugang an.', 'Zu dieser Adresse gibt es bereits einen Datensatz. Bitte fordern Sie einen Mitgliederzugang an.')]);
                }
                $current = Member::query()->create(['member_number' => ((int) Member::query()->max('member_number')) + 1, 'email' => $email, 'first_name' => $values['first_name'], 'last_name' => $values['last_name']]);
            }
            if ($current->payment_method === 'SEPA-Lastschrift') {
                $this->mandates->revoke($current, 'Durch einen neuen Mitgliedsantrag ersetzt');
                $updates = [...$updates, ...$this->mandates->clearedDetails()];
            }
            $this->changes->save($current, $updates);
            if ($requiresApproval) {
                DB::table('membership_applications')->updateOrInsert(
                    ['member_id' => $current->id],
                    [
                        'membership_type' => $values['membership_type'],
                        'submitted_at' => now(),
                        'approved_at' => null,
                        'approved_by' => null,
                        'approved_by_name' => null,
                    ],
                );
            }
            if ($values['payment_method'] === 'SEPA-Lastschrift') {
                $this->changes->save($current, SubmissionGuard::mandateDetails($current, $values));
            }
            $applicationPdf = $this->documents->store($current, 'application', (string) $request->signature(), $request->signature('guardian_signature'), $values['guardian_name'] ?? null, $request);
            $mandateSignature = $request->signature('mandate_signature');
            if ($mandateSignature !== null) {
                $this->documents->store($current, 'sepa', $mandateSignature, null, null, $request);
            }

            return ['member' => $current, 'applicationPdf' => $applicationPdf];
        }, attempts: 3);
    }
}
