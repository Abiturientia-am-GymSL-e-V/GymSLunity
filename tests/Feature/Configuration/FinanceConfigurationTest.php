<?php

namespace Tests\Feature\Configuration;

use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_enable_small_business_regulation(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $settings = ClubSetting::current();

        $this->get('/konfiguration/buchhaltung')->assertInertia(fn (Assert $page) => $page
            ->component('configuration/Finance')
            ->where('smallBusinessRegulationEnabled', false)
            ->where('version', $settings->version));

        $this->patch('/konfiguration/buchhaltung', [
            'version' => $settings->version,
            'small_business_regulation_enabled' => true,
        ])->assertSessionHasNoErrors()->assertRedirect(route('configuration.finance.edit'));

        $this->assertTrue((bool) ClubSetting::current()->data['small_business_regulation_enabled']);
        $this->assertDatabaseHas('configuration_changes', ['subject' => 'Buchhaltung']);
    }

    public function test_finance_configuration_is_validated_and_restricted_to_administrators(): void
    {
        $settings = ClubSetting::current();
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->patch('/konfiguration/buchhaltung', [
            'version' => $settings->version,
            'small_business_regulation_enabled' => 'yes',
        ])->assertSessionHasErrors('small_business_regulation_enabled');
        $this->patch('/konfiguration/buchhaltung', [
            'version' => $settings->version + 1,
            'small_business_regulation_enabled' => true,
        ])->assertSessionHasErrors('version');

        $this->actingAs(User::factory()->create(['roles' => ['bh']]));
        $this->get('/konfiguration/buchhaltung')->assertForbidden();
        $this->patch('/konfiguration/buchhaltung', [
            'version' => $settings->version,
            'small_business_regulation_enabled' => true,
        ])->assertForbidden();
    }
}
