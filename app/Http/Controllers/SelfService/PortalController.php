<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfService;

use App\Calendar\CalendarAccess;
use App\Configuration\SoftwareModules;
use App\Http\Controllers\Controller;
use App\Members\MemberFields;
use App\SelfService\Access;
use App\SelfService\PortalRules;
use App\SelfService\PortalView;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PortalController extends Controller
{
    public function __invoke(Request $request, CalendarAccess $calendarAccess, PortalView $view, PortalRules $rules): Response
    {
        $member = Access::member($request);
        $calendarEnabled = SoftwareModules::enabled('calendar');
        $calendars = $calendarEnabled ? $calendarAccess->forMember($member) : collect();
        $calendarToken = DB::table('member_calendar_tokens')->where('member_id', $member->id)->value('token');
        if ($calendars->isNotEmpty() && ! is_string($calendarToken)) {
            DB::table('member_calendar_tokens')->insertOrIgnore(['member_id' => $member->id, 'token' => bin2hex(random_bytes(24)), 'created_at' => now(), 'updated_at' => now()]);
            $calendarToken = DB::table('member_calendar_tokens')->where('member_id', $member->id)->value('token');
        }

        $profileSections = $view->profileSections($member);
        $visibleKeys = collect($profileSections)->flatMap(fn (array $section): array => array_column($section['fields'], 'key'))->all();
        $pendingCancellation = DB::table('membership_cancellations')
            ->where('member_id', $member->id)
            ->whereNull('confirmed_at')
            ->whereNull('withdrawn_at')
            ->first(['requested_at']);

        return Inertia::render('selfservice/Portal', [
            'member' => [
                ...Arr::only(MemberFields::snapshot($member), $visibleKeys),
                ...Arr::only($member->attributesToArray(), ['member_number', 'first_name', 'email', 'membership_type', 'payment_method', 'sponsor_contribution', 'joined_at', 'left_at', 'lock_version']),
            ],
            'profileSections' => $profileSections,
            'documents' => DB::table('member_documents')
                ->where('member_id', $member->id)
                ->where(function ($query) use ($member): void {
                    $query->where('kind', 'application');
                    if ($member->payment_method === 'SEPA-Lastschrift' && $member->mandate_reference) {
                        $query->orWhere(fn ($mandate) => $mandate
                            ->where('kind', 'sepa')
                            ->whereNull('revoked_at')
                            ->where('mandate_reference', $member->mandate_reference));
                    }
                })
                ->distinct()
                ->pluck('kind'),
            'canJoin' => $rules->canJoin($member),
            'isActiveMember' => $member->hasActiveOrUpcomingMembership(),
            'pendingApplication' => DB::table('membership_applications')->where('member_id', $member->id)->whereNull('approved_at')->first(['membership_type', 'submitted_at']),
            'canRequestCancellation' => $rules->mayRequestCancellation($member, $pendingCancellation !== null),
            'pendingCancellation' => $pendingCancellation,
            'calendarEnabled' => $calendarEnabled,
            'bookingsEnabled' => SoftwareModules::enabled('bookings'),
            'calendarSubscription' => $calendars->isEmpty() ? null : [
                'url' => route('calendar.feed.member', $calendarToken),
                'calendars' => $calendars->map(fn ($calendar): array => ['name' => $calendar->name, 'color' => $calendar->color])->values(),
            ],
            'contributionAccount' => SoftwareModules::enabled('payments') ? $view->contributionAccount($member) : null,
        ]);
    }
}
