<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Communication\CommunicationTemplate;
use App\Forms\FinanceMandateText;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\User;
use App\SelfService\FormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClubPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    private const CREDITOR_ID = 'DE98ZZZ09999999999';

    protected function setUp(): void
    {
        parent::setUp();
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'name' => 'Testverein e. V.', 'creditor_id' => self::CREDITOR_ID]]);
    }

    public function test_creditor_id_has_the_german_placeholder_everywhere(): void
    {
        $communication = array_column(app(CommunicationTemplate::class)->placeholders(), 'token');

        foreach ([FormTemplates::placeholders(), $communication, FinanceMandateText::placeholders()] as $placeholders) {
            $this->assertContains('{{verein.glaeubiger_id}}', $placeholders);
            $this->assertNotContains('{{verein.creditor_id}}', $placeholders);
        }
        $this->assertStringContainsString('{{verein.glaeubiger_id}}', FormTemplates::defaults()['sepa_text']);
    }

    public function test_texts_with_the_former_placeholder_keep_working(): void
    {
        $member = Member::factory()->create();
        $template = app(CommunicationTemplate::class);

        foreach (['{{verein.glaeubiger_id}}', '{{verein.creditor_id}}'] as $placeholder) {
            FormTemplates::validate("Gläubiger-ID: $placeholder");
            $template->validate("Gläubiger-ID: $placeholder", 'body');

            $this->assertSame('Gläubiger-ID: '.self::CREDITOR_ID, FormTemplates::renderText("Gläubiger-ID: $placeholder"));
            $this->assertSame('Gläubiger-ID: '.self::CREDITOR_ID, $template->render("Gläubiger-ID: $placeholder", $member));
            $this->assertSame('Gläubiger-ID: '.self::CREDITOR_ID, $template->renderHtml("Gläubiger-ID: $placeholder", $member));
        }
    }

    public function test_finance_configuration_lists_the_placeholders_it_accepts(): void
    {
        $settings = ClubSetting::current();
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));

        $this->get('/konfiguration/buchhaltung')->assertInertia(fn (Assert $page) => $page
            ->where('mandatePlaceholders', ['{{verein.name}}', '{{verein.glaeubiger_id}}']));

        $this->patch('/konfiguration/buchhaltung', [
            'version' => $settings->version,
            'small_business_regulation_enabled' => false,
            'finance_mandate_text' => 'Ich ermächtige {{verein.name}} ({{verein.glaeubiger_id}}).',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Ich ermächtige Testverein e. V. ('.self::CREDITOR_ID.').',
            FinanceMandateText::render((string) ClubSetting::current()->data['finance_mandate_text'], ClubSetting::current()->data),
        );
    }

    public function test_migration_renames_the_placeholder_in_saved_texts(): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [
            ...$settings->data,
            'sepa_text' => 'Gläubiger-ID: {{verein.creditor_id}}',
            'imprint_text' => "Gläubiger-ID {{verein.creditor_id}}\n{{verein.name}}",
            'creditor_id' => 'creditor_id bleibt als Wert unverändert',
        ]]);
        $migration = require database_path('migrations/2026_10_01_020000_rename_creditor_id_placeholder.php');

        $migration->up();
        $migration->up();

        $data = ClubSetting::current()->data;
        $this->assertSame('Gläubiger-ID: {{verein.glaeubiger_id}}', $data['sepa_text']);
        $this->assertSame("Gläubiger-ID {{verein.glaeubiger_id}}\n{{verein.name}}", $data['imprint_text']);
        $this->assertSame('creditor_id bleibt als Wert unverändert', $data['creditor_id']);
    }
}
