<?php

declare(strict_types=1);

namespace App\SelfService;

use App\Configuration\ClubSettings;
use App\Http\Requests\SelfService\SepaMandateRequest;
use App\Members\MemberMandates;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitSepaMandate
{
    public function __construct(
        private readonly Documents $documents,
        private readonly ProfileChanges $changes,
        private readonly MemberMandates $mandates,
    ) {}

    public function handle(SepaMandateRequest $request): Member
    {
        $values = $request->validated();
        $member = $request->member();

        return DB::transaction(function () use ($request, $values, $member): Member {
            $settings = SubmissionGuard::lockConfiguration((int) $values['version']);
            $current = SubmissionGuard::lockMember($member, $values);
            abort_unless($current?->hasActiveOrUpcomingMembership(), 403);
            $missing = ClubSettings::missingSepaFieldsIn($settings->data);
            if ($missing !== []) {
                throw ValidationException::withMessages(['accepted' => 'Der Verein muss zunächst folgende Angaben konfigurieren: '.implode(', ', $missing).'.']);
            }
            $this->mandates->revoke($current, 'Durch ein neues SEPA-Mandat ersetzt');
            $this->changes->save($current, [...SubmissionGuard::mandateDetails($current, $values), 'payment_method' => 'SEPA-Lastschrift']);
            $this->documents->store($current, 'sepa', (string) $request->signature(), null, null, $request);

            return $current;
        }, attempts: 3);
    }
}
