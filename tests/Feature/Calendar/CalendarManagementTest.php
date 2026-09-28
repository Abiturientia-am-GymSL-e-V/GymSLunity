<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Models\ClubCalendar;
use App\Models\ClubCalendarEvent;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarManagementTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role = 'vereinsverwaltung'): void
    {
        $this->actingAs(User::factory()->create(['roles' => [$role]]));
    }

    public function test_default_calendars_and_month_view_are_available(): void
    {
        $this->signIn();

        $this->get(route('calendar.index', ['month' => '2026-09']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Calendar')->where('month', '2026-09')->has('calendars', 2)
            ->where('calendars.0.name', 'Geburtstage')->where('calendars.1.name', 'Allgemeiner Vereinskalender'));

        $this->assertDatabaseHas('club_calendars', ['type' => 'birthdays']);
        $this->assertDatabaseHas('club_calendars', ['type' => 'general']);
    }

    public function test_calendars_and_multiday_events_can_be_managed(): void
    {
        $this->signIn();
        $this->post(route('calendar.store'), ['name' => 'Vorstand', 'color' => '#7c3aed'])->assertSessionHasNoErrors();
        $calendar = ClubCalendar::query()->where('name', 'Vorstand')->sole();

        $this->post(route('calendar.events.store'), [
            'calendar_id' => $calendar->id, 'title' => 'Klausurtagung', 'location' => 'Vereinsheim',
            'description' => 'Planung', 'starts_at' => '2026-09-25T09:00', 'ends_at' => '2026-09-27T16:00', 'all_day' => false,
        ])->assertSessionHasNoErrors();
        $event = ClubCalendarEvent::sole();
        $this->assertSame('2026-09-25 09:00:00', $event->starts_at->format('Y-m-d H:i:s'));

        $this->patch(route('calendar.events.update', $event), [
            'calendar_id' => $calendar->id, 'title' => 'Klausur', 'location' => '', 'description' => '',
            'starts_at' => '2026-09-26T00:00', 'ends_at' => '2026-09-27T00:00', 'all_day' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-09-28 00:00:00', $event->fresh()->ends_at->format('Y-m-d H:i:s'));

        $this->delete(route('calendar.events.destroy', $event))->assertSessionHasNoErrors();
        $this->delete(route('calendar.destroy', $calendar))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('club_calendar_events', 0);
    }

    public function test_public_and_member_specific_combined_feeds_are_ical(): void
    {
        $this->signIn();
        $calendar = ClubCalendar::query()->where('type', 'general')->sole();
        $this->put(route('calendar.rules.update', $calendar), ['rules' => [['field_key' => 'membership_type', 'value' => 'Aktiv/ordentliches Mitglied']]])->assertSessionHasNoErrors();
        $this->post(route('calendar.public-link.store', $calendar))->assertSessionHasNoErrors();
        $calendar->refresh();
        ClubCalendarEvent::query()->create([
            'club_calendar_id' => $calendar->id, 'title' => 'Sommerfest', 'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addHours(2), 'all_day' => false,
        ]);

        $this->get(route('calendar.feed.public', $calendar->public_token))->assertOk()
            ->assertHeader('content-type', 'text/calendar; charset=utf-8')->assertSee('SUMMARY:Sommerfest', false);

        $member = Member::factory()->create(['membership_type' => 'Aktiv/ordentliches Mitglied']);
        $token = bin2hex(random_bytes(24));
        DB::table('member_calendar_tokens')->insert(['member_id' => $member->id, 'token' => $token, 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('calendar.feed.member', $token))->assertOk()->assertSee('X-WR-CALNAME:Meine Vereinskalender', false)->assertSee('SUMMARY:Sommerfest', false);
    }

    public function test_matching_members_receive_a_personal_combined_link_in_portal(): void
    {
        $calendar = ClubCalendar::query()->where('type', 'general')->sole();
        $calendar->rules()->create(['field_key' => 'club_role', 'value' => 'Vorstand']);
        $member = Member::factory()->create(['club_role' => 'Vorstand']);
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'selfservice_enabled' => true]]);

        $session = ['selfservice' => ['email' => strtolower($member->email), 'member_id' => $member->id, 'until' => time() + 1800]];
        $this->withSession($session)->get('/selfservice')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('calendarSubscription.calendars.0.name', $calendar->name)
            ->where('calendarSubscription.url', fn (string $url): bool => str_contains($url, '/kalender/mein-abo/')));
        $this->assertDatabaseHas('member_calendar_tokens', ['member_id' => $member->id]);
    }

    public function test_calendar_permission_is_enforced(): void
    {
        $this->signIn('bh');
        $this->get(route('calendar.index'))->assertForbidden();
        $this->post(route('calendar.store'), ['name' => 'Nicht erlaubt', 'color' => '#000000'])->assertForbidden();
    }
}
