<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfService;

use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\SelfService\MembershipApplicationRequest;
use App\Http\Requests\SelfService\SepaMandateRequest;
use App\Mail\MembershipWelcomeMail;
use App\Members\MemberMandates;
use App\Members\WelcomeMails;
use App\Models\Member;
use App\SelfService\Access;
use App\SelfService\FormTemplates;
use App\SelfService\PortalOptions;
use App\SelfService\PortalRules;
use App\SelfService\SubmitMembershipApplication;
use App\SelfService\SubmitSepaMandate;
use App\Support\FormOfAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

/** Signed forms: membership application and SEPA mandate. */
class FormController extends Controller
{
    public function show(Request $request, string $kind, ClubSettings $settings, PortalOptions $options, PortalRules $rules): Response
    {
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        $email = Access::email($request);
        $member = $request->session()->get('selfservice.member_id') ? Access::member($request) : null;
        abort_unless($kind === 'sepa' ? $member?->hasActiveOrUpcomingMembership() : $rules->mayJoin($member), 403);

        return Inertia::render('selfservice/Form', [
            'kind' => $kind, 'email' => $email,
            'requiresApproval' => $settings->get('membership_activation', 'immediate') === 'approval',
            'member' => $member ? Arr::only($member->attributesToArray(), [
                ...MembershipApplicationRequest::PROFILE, ...MemberMandates::DETAILS, 'sponsor_contribution', 'lock_version',
            ]) : null,
            'hasActiveMandate' => $member !== null && $this->hasActiveMandate($member),
            'texts' => FormTemplates::rendered(), 'version' => $settings->version(),
            'membershipOptions' => $options->membership(),
            'paymentOptions' => $options->payment(),
            'genderOptions' => $options->gender(),
        ]);
    }

    public function storeApplication(MembershipApplicationRequest $request, SubmitMembershipApplication $submit, WelcomeMails $welcomeMails): RedirectResponse
    {
        $email = $request->email();
        $result = $submit->handle($request);
        $member = $result['member'];
        $welcomeMail = new MembershipWelcomeMail($member->member_number, $result['applicationPdf']);
        // The application is already committed; delivery failures must not undo it.
        defer(function () use ($email, $welcomeMail): void {
            try {
                Mail::to($email)->send($welcomeMail);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
        // Only immediate activation; approved applications trigger it on approval.
        $welcomeMails->sendAutomaticallyLater($member);
        Access::signIn($request, $email, $member->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => $member->joined_at
            ? FormOfAddress::choose('Dein Beitritt wurde gespeichert. Du kannst jetzt dein SEPA-Mandat anlegen.', 'Ihr Beitritt wurde gespeichert. Sie können jetzt Ihr SEPA-Mandat anlegen.')
            : FormOfAddress::choose('Dein Antrag wurde gespeichert und wartet auf Freigabe durch den Vorstand.', 'Ihr Antrag wurde gespeichert und wartet auf Freigabe durch den Vorstand.')]);

        return redirect('/selfservice');
    }

    public function storeMandate(SepaMandateRequest $request, SubmitSepaMandate $submit): RedirectResponse
    {
        $member = $submit->handle($request);
        Access::signIn($request, $request->email(), $member->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => FormOfAddress::choose('Dein SEPA-Mandat wurde gespeichert.', 'Ihr SEPA-Mandat wurde gespeichert.')]);

        return redirect('/selfservice');
    }

    private function hasActiveMandate(Member $member): bool
    {
        return $member->payment_method === 'SEPA-Lastschrift'
            && $member->mandate_reference
            && DB::table('member_documents')
                ->where('member_id', $member->id)
                ->where('kind', 'sepa')
                ->whereNull('revoked_at')
                ->where('mandate_reference', $member->mandate_reference)
                ->exists();
    }
}
