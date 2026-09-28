<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Configuration\ClubSettings;
use App\Models\ClubSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClubSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $values */
    private function configure(array $values): ClubSettings
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, ...$values]]);

        return app(ClubSettings::class);
    }

    public function test_sepa_readiness_requires_exactly_the_fields_the_xml_export_needs(): void
    {
        $settings = $this->configure(['name' => 'Verein e. V.', 'iban' => 'DE89370400440532013000', 'creditor_id' => 'DE98ZZZ09999999999', 'bic' => '']);

        $this->assertTrue($settings->sepaReady(), 'The BIC is optional in SEPA and must not block readiness.');
        $this->assertSame([], $settings->missingSepaFields());

        $settings = $this->configure(['iban' => '  ', 'creditor_id' => null]);

        $this->assertFalse($settings->sepaReady());
        $this->assertSame(['Vereins-IBAN', 'SEPA-Gläubiger-ID'], $settings->missingSepaFields());
    }

    public function test_mandate_readiness_requires_address_and_creditor_id(): void
    {
        $complete = ['name' => 'Verein e. V.', 'street' => 'Weg 1', 'postal_code' => '12345', 'city' => 'Ort', 'country' => 'DE', 'creditor_id' => 'DE98ZZZ09999999999'];

        $this->assertTrue($this->configure($complete)->mandateReady());
        $this->assertFalse($this->configure(['street' => ''])->mandateReady());
    }

    public function test_display_name_prefers_the_short_name(): void
    {
        $this->assertSame('SV', $this->configure(['name' => 'Sportverein e. V.', 'short_name' => 'SV'])->displayName());
        $this->assertSame('Sportverein e. V.', $this->configure(['short_name' => ' '])->displayName());
        $this->assertNull($this->configure(['name' => '', 'short_name' => ''])->displayName());
    }

    public function test_logo_url_is_versioned_and_absent_without_logo(): void
    {
        $this->assertNull($this->configure(['logo_path' => null])->logoUrl());

        $settings = $this->configure(['logo_path' => 'branding/logo-00000000-0000-0000-0000-000000000000.png']);

        $this->assertSame(route('branding.logo', ['v' => $settings->version()]), $settings->logoUrl());
    }

    public function test_the_configuration_is_loaded_once_and_refreshed_after_writes(): void
    {
        $settings = app(ClubSettings::class);
        $settings->refresh();

        DB::enableQueryLog();
        $settings->data();
        $settings->version();
        $settings->sepaReady();
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();

        // A locked write elsewhere must not leave a stale cached copy behind.
        $locked = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
        $locked->update(['data' => [...$locked->data, 'name' => 'Neuer Name']]);
        $this->assertSame('Neuer Name', $settings->text('name'));

        // increment() fires "updated" but not "saved".
        $before = $settings->fieldsVersion();
        $locked->increment('fields_version');
        $this->assertSame($before + 1, $settings->fieldsVersion());
    }

    public function test_every_request_starts_with_a_fresh_configuration(): void
    {
        app(ClubSettings::class)->data();
        // Bypass model events, as the configuration backup restore does.
        DB::table('club_settings')->where('id', 1)->update(['data' => json_encode([...ClubSetting::current()->data, 'selfservice_enabled' => true])]);

        $this->get('/up')->assertOk();

        $this->assertTrue(app(ClubSettings::class)->enabled('selfservice_enabled'));
    }
}
