<?php

declare(strict_types=1);

namespace App\Http\Controllers\Calendar;

use App\Calendar\CalendarAccess;
use App\Calendar\CalendarEntries;
use App\Calendar\Icalendar;
use App\Configuration\ClubSettings;
use App\Http\Controllers\Controller;
use App\Models\ClubCalendar;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CalendarFeedController extends Controller
{
    public function publicFeed(string $token, CalendarEntries $entries, Icalendar $ical): Response
    {
        $calendar = ClubCalendar::query()->where('public_token', $token)->where('type', '!=', 'birthdays')->firstOrFail();

        return $this->response(collect([$calendar]), $calendar->name, $entries, $ical);
    }

    public function memberFeed(string $token, CalendarAccess $access, CalendarEntries $entries, Icalendar $ical, ClubSettings $settings): Response
    {
        abort_unless($settings->enabled('selfservice_enabled'), 404);
        $row = DB::table('member_calendar_tokens')->where('token', $token)->first();
        abort_unless($row !== null, 404);
        $member = Member::query()->find($row->member_id);
        // Former members keep the link in their calendar app, but it stops working.
        abort_unless($member instanceof Member && $member->isCurrentMember(), 404);

        return $this->response($access->forMember($member), 'Meine Vereinskalender', $entries, $ical);
    }

    /** @param Collection<int, ClubCalendar> $calendars */
    private function response(Collection $calendars, string $name, CalendarEntries $entries, Icalendar $ical): Response
    {
        $from = CarbonImmutable::today()->subYear()->startOfYear();
        $until = CarbonImmutable::today()->addYears(4)->endOfYear()->addDay();
        $body = $ical->render($entries->between($calendars, $from, $until), $name);

        return response($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'inline; filename="vereinskalender.ics"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
