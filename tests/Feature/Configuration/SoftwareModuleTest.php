<?php

namespace Tests\Feature\Configuration;

use App\Configuration\SoftwareModules;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SoftwareModuleTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
    }

    /** @return array<string, bool> */
    private function modules(bool $enabled = true): array
    {
        return array_fill_keys(array_keys(SoftwareModules::OPTIONAL), $enabled);
    }

    public function test_all_optional_modules_are_enabled_by_default(): void
    {
        $this->signIn();

        $this->get(route('configuration.modules.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('configuration/SoftwareModules')
            ->where('modules', $this->modules())
            ->has('definitions', count(SoftwareModules::OPTIONAL)));
    }

    public function test_modules_can_be_disabled_and_are_audited(): void
    {
        $this->signIn();
        $settings = ClubSetting::current();
        $modules = $this->modules();
        $modules['payments'] = false;
        $modules['calendar'] = false;

        $this->patch(route('configuration.modules.update'), ['version' => $settings->version, 'modules' => $modules])
            ->assertSessionHasNoErrors();

        $settings->refresh();
        $this->assertSame($modules, $settings->data['software_modules']);
        $this->assertDatabaseHas('configuration_changes', ['subject' => 'Softwaremodule']);
    }

    public function test_disabled_modules_are_hidden_from_navigation_and_dashboard(): void
    {
        $this->signIn();
        Member::factory()->create();
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'software_modules' => $this->modules(false)]]);

        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.viewPayments', false)
            ->where('can.viewStatistics', false)
            ->where('can.viewFinance', false)
            ->where('can.viewForms', false)
            ->where('can.viewDonations', false)
            ->where('can.viewInventory', false)
            ->where('can.viewCalendar', false)
            ->where('can.viewBookings', false)
            ->where('can.viewCommunication', false)
            ->has('memberOverview')
            ->has('birthdays')
            ->where('contributionOverview', null)
            ->where('donationOverview', null)
            ->where('upcomingEvents', null));
    }

    public function test_disabled_module_routes_are_not_available_but_configuration_remains_accessible(): void
    {
        $this->signIn();
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'software_modules' => $this->modules(false)]]);

        foreach (['payments', 'statistics', 'finance', 'forms', 'donations', 'inventory', 'calendar.index', 'bookings.index', 'kommunikation'] as $route) {
            $this->get(route($route))->assertNotFound();
        }
        $this->get(route('calendar.feed.public', 'nicht-verfuegbar'))->assertNotFound();
        $this->get(route('configuration.modules.edit'))->assertOk();
        $this->get(route('members.index'))->assertOk();
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_module_update_requires_every_known_boolean_and_current_version(): void
    {
        $this->signIn();
        $settings = ClubSetting::current();

        $this->patch(route('configuration.modules.update'), ['version' => $settings->version, 'modules' => ['payments' => true]])
            ->assertSessionHasErrors('modules.statistics');
        $this->patch(route('configuration.modules.update'), ['version' => $settings->version + 1, 'modules' => $this->modules()])
            ->assertSessionHasErrors('version');
    }
}
