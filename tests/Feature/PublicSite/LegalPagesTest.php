<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSite;

use App\Models\ClubSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private function club(array $values): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'name' => 'Testverein e. V.', 'email' => 'info@example.org', ...$values]]);
    }

    public function test_imprint_shows_entered_details_and_hides_empty_optional_lines(): void
    {
        $this->club(['representatives' => 'Erika Muster (Vorsitzende)', 'register_court' => 'Amtsgericht Siegen', 'register_number' => 'VR 1234', 'vat_id' => '']);

        $this->get('/impressum')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('text', fn (string $text): bool => str_contains($text, 'Vertretungsberechtigt: Erika Muster (Vorsitzende)')
                && str_contains($text, 'Registergericht: Amtsgericht Siegen')
                && ! str_contains($text, 'Umsatzsteuer')
                && ! str_contains($text, "\n\n\n")));
    }

    public function test_privacy_policy_names_the_mandatory_information(): void
    {
        $this->club(['supervisory_authority' => 'LDI NRW, Postfach 20 04 44, 40102 Düsseldorf', 'hosting_provider' => '']);

        $this->get('/datenschutz')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('text', function (string $text): bool {
                foreach (['Art. 6 Abs. 1', 'Art. 15 DSGVO', 'Art. 21 DSGVO', 'Art. 77 DSGVO', 'Für uns zuständige Aufsichtsbehörde: LDI NRW', 'Speicherdauer', '§ 25 Abs. 2 Nr. 2 TDDDG', 'info@example.org'] as $needle) {
                    if (! str_contains($text, $needle)) {
                        return false;
                    }
                }

                return ! str_contains($text, 'Hosting der Website') && ! str_contains($text, 'Datenschutzbeauftragte Person:');
            }));
    }

    public function test_customised_texts_also_hide_lines_with_empty_placeholders(): void
    {
        $this->club(['imprint_text' => "Impressum\nVorstand: {{verein.representatives}}\nKontakt: {{verein.email}}", 'representatives' => null]);

        $this->get('/impressum')->assertInertia(fn (Assert $page) => $page->where('text', "Impressum\nKontakt: info@example.org"));
    }
}
