<?php

declare(strict_types=1);

namespace Tests\Feature\Bookings;

use App\Bookings\BookingManager;
use App\Configuration\SoftwareModules;
use App\Models\BookingResource;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\ResourceBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookingSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_administration_can_create_resources_and_manual_external_bookings(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        $this->actingAs($admin)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Bookings')->where('activeTab', 'calendar'));

        $this->post(route('bookings.resources.store'), [
            'name' => 'Vereinsheim', 'description' => 'Ganzes Gebäude', 'location' => 'Hauptstraße 1',
            'parent_id' => null, 'allowed_membership_types' => [],
            'auto_approve_membership_types' => [], 'price_mode' => 'day',
            'price' => '50,00', 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $resource = BookingResource::query()->sole();
        $this->assertSame(5000, $resource->price_cents);

        $this->post(route('bookings.store'), [
            'resource_id' => $resource->id, 'member_id' => null,
            'requester_name' => 'Jugendabteilung', 'title' => 'Sommerfest',
            'starts_at' => '2026-10-10T10:00', 'ends_at' => '2026-10-10T18:00',
            'recurrence' => 'none', 'occurrences' => 1,
        ])->assertSessionHasNoErrors();

        $booking = ResourceBooking::query()->sole();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('Jugendabteilung', $booking->requester_name);
        $this->assertSame(5000, $booking->price_cents);
        $this->assertNull($booking->member_id);
        $this->assertNull($booking->charge_transaction_id);
    }

    public function test_resource_hierarchy_and_auto_approval_are_validated(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $defaults = ['allowed_membership_types' => [], 'auto_approve_membership_types' => [], 'price_mode' => 'free', 'is_active' => true];
        $building = BookingResource::query()->create([...$defaults, 'name' => 'Gebäude', 'price_cents' => 0]);
        $room = BookingResource::query()->create([...$defaults, 'name' => 'Raum', 'parent_id' => $building->id, 'price_cents' => 0]);
        $payload = fn (array $values): array => [...$defaults, 'description' => null, 'location' => null, 'inventory_item_id' => null, 'price' => null, ...$values];

        $this->patch(route('bookings.resources.update', $building), $payload(['name' => 'Gebäude', 'parent_id' => $room->id]))
            ->assertSessionHasErrors(['parent_id' => 'Eine Ressource kann nicht unter sich selbst oder einer eigenen Teilressource eingeordnet werden.']);
        $this->patch(route('bookings.resources.update', $building), $payload(['name' => 'Gebäude', 'parent_id' => $building->id]))
            ->assertSessionHasErrors('parent_id');
        $this->assertNull($building->fresh()->parent_id);

        $types = array_keys(app(BookingManager::class)->membershipTypes());
        $this->post(route('bookings.resources.store'), $payload([
            'name' => 'Halle', 'parent_id' => null,
            'allowed_membership_types' => [$types[0]], 'auto_approve_membership_types' => [$types[1]],
        ]))->assertSessionHasErrors(['auto_approve_membership_types' => 'Automatische Freigaben sind nur für zugelassene Mitgliedsarten möglich.']);

        $this->patch(route('bookings.resources.update', $room), $payload(['name' => 'Raum 1', 'parent_id' => $building->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Raum 1', $room->fresh()->name);
    }

    public function test_member_series_is_auto_confirmed_charged_and_single_occurrence_can_be_cancelled(): void
    {
        $this->enablePortal();
        $member = Member::factory()->create([
            'membership_type' => 'Aktiv/ordentliches Mitglied',
            'joined_at' => '2020-01-01', 'left_at' => null, 'deceased_at' => null,
        ]);
        $building = BookingResource::query()->create([
            'name' => 'Sportzentrum', 'price_mode' => 'free', 'price_cents' => 0,
            'allowed_membership_types' => [], 'auto_approve_membership_types' => [], 'is_active' => true,
        ]);
        $room = BookingResource::query()->create([
            'parent_id' => $building->id, 'name' => 'Kraftraum', 'price_mode' => 'hour', 'price_cents' => 1000,
            'allowed_membership_types' => ['Aktiv/ordentliches Mitglied'],
            'auto_approve_membership_types' => ['Aktiv/ordentliches Mitglied'], 'is_active' => true,
        ]);

        $this->memberSession($member)
            ->get(route('selfservice.bookings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('selfservice/Bookings')->has('resources', 2));

        $this->post(route('selfservice.bookings.store'), [
            'resource_id' => $room->id, 'title' => 'Training',
            'starts_at' => '2026-11-02T18:00', 'ends_at' => '2026-11-02T20:00',
            'recurrence' => 'weekly', 'occurrences' => 2,
        ])->assertSessionHasNoErrors();

        $bookings = ResourceBooking::query()->orderBy('occurrence')->get();
        $this->assertCount(2, $bookings);
        $this->assertNotNull($bookings[0]->series_id);
        $this->assertSame($bookings[0]->series_id, $bookings[1]->series_id);
        $this->assertSame(['confirmed', 'confirmed'], $bookings->pluck('status')->all());
        $this->assertSame([2000, 2000], $bookings->pluck('price_cents')->all());
        $this->assertSame(4000, $member->contributionAccount->fresh()->balance_cents);
        $this->assertDatabaseCount('contribution_transactions', 2);

        $this->post(route('selfservice.bookings.store'), [
            'resource_id' => $building->id, 'title' => 'Doppelbelegung',
            'starts_at' => '2026-11-02T19:00', 'ends_at' => '2026-11-02T21:00',
            'recurrence' => 'none', 'occurrences' => 1,
        ])->assertSessionHasErrors('starts_at');

        $this->delete(route('selfservice.bookings.cancel', $bookings[0]))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $bookings[0]->fresh()->status);
        $this->assertSame('confirmed', $bookings[1]->fresh()->status);
        $this->assertSame(2000, $member->contributionAccount->fresh()->balance_cents);
        $this->assertDatabaseHas('contribution_transactions', [
            'kind' => 'booking_refund', 'amount_cents' => -2000,
        ]);
    }

    public function test_requested_booking_is_charged_only_after_administrative_approval(): void
    {
        $this->enablePortal();
        $member = Member::factory()->create([
            'membership_type' => 'Fördermitglied', 'joined_at' => '2020-01-01',
            'left_at' => null, 'deceased_at' => null,
        ]);
        $resource = BookingResource::query()->create([
            'name' => 'Beamer', 'price_mode' => 'once', 'price_cents' => 500,
            'allowed_membership_types' => [], 'auto_approve_membership_types' => [], 'is_active' => true,
        ]);
        $this->memberSession($member)->post(route('selfservice.bookings.store'), [
            'resource_id' => $resource->id, 'title' => 'Vortrag',
            'starts_at' => '2026-12-01T18:00', 'ends_at' => '2026-12-01T20:00',
            'recurrence' => 'none', 'occurrences' => 1,
        ])->assertSessionHasNoErrors();
        $booking = ResourceBooking::query()->sole();
        $this->assertSame('requested', $booking->status);
        $this->assertSame(0, $member->contributionAccount->fresh()->balance_cents);

        $admin = User::factory()->create(['roles' => ['admin']]);
        $this->actingAs($admin)
            ->get(route('bookings.requests.index'))
            ->assertInertia(fn (Assert $page) => $page->has('requests', 1));
        $this->patch(route('bookings.decide', $booking), ['decision' => 'approve'])->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(500, $member->contributionAccount->fresh()->balance_cents);
        $this->assertDatabaseHas('contribution_transactions', ['kind' => 'booking', 'amount_cents' => 500]);
    }

    public function test_disabled_booking_module_hides_navigation_and_blocks_routes(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        $settings = ClubSetting::current();
        $modules = array_fill_keys(array_keys(SoftwareModules::OPTIONAL), true);
        $modules['bookings'] = false;
        $settings->update(['data' => [...$settings->data, 'software_modules' => $modules, 'selfservice_enabled' => true]]);

        $this->actingAs($admin)->get(route('bookings.index'))->assertNotFound();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('can.viewBookings', false));

        $member = Member::factory()->create(['joined_at' => '2020-01-01']);
        $this->memberSession($member)->get(route('selfservice.bookings.index'))->assertNotFound();
    }

    private function enablePortal(): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'selfservice_enabled' => true]]);
    }

    private function memberSession(Member $member): static
    {
        return $this->withSession(['selfservice' => [
            'email' => strtolower((string) $member->email),
            'member_id' => $member->id,
            'until' => now()->getTimestamp() + 1800,
        ]]);
    }
}
