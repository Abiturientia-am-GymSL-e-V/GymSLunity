<?php

namespace Tests\Feature\Donations;

use App\Mail\DonationCertificateMail;
use App\Models\ClubSetting;
use App\Models\Donation;
use App\Models\DonationCertificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class DonationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role = 'bh'): User
    {
        $user = User::factory()->create(['roles' => [$role]]);
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function donationData(array $overrides = []): array
    {
        return [
            'donor_name' => 'Erika Mustermann',
            'donor_street' => 'Musterweg 1',
            'donor_postal_code' => '10115',
            'donor_city' => 'Berlin',
            'donor_country' => 'DE',
            'donor_email' => 'erika@example.invalid',
            'donation_type' => 'money',
            'amount' => '123,45',
            'donated_at' => '2026-09-01',
            'purpose_code' => '52-21',
            'description' => '',
            'asset_origin' => null,
            'valuation_document_reference' => null,
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function readyClubData(): array
    {
        return [
            'name' => 'Sportverein Beispiel e. V.',
            'street' => 'Vereinsweg 2',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'register_number' => 'VR 12345',
            'register_court' => 'Amtsgericht Berlin',
            'tax_number' => '27/123/45678',
            'tax_office' => 'Berlin',
            'donation_purpose_codes' => ['52-21'],
            'contributions_tax_deductible' => false,
            'tax_privilege_notice_type' => 'exemption_notice',
            'tax_privilege_notice_date' => '2025-05-20',
            'tax_privilege_assessment_period' => '2024',
            'certificate_location' => 'Berlin',
            'certificate_machine_generated_notified' => true,
        ];
    }

    public function test_donation_section_is_protected_and_lists_open_donations(): void
    {
        $this->get(route('donations'))->assertRedirect(route('login'));
        $this->signIn('bv');
        $this->get(route('donations'))->assertForbidden();
        $this->signIn();
        ClubSetting::current()->update(['data' => $this->readyClubData()]);
        $this->post(route('donations.store'), $this->donationData())->assertSessionHasNoErrors();

        $this->get(route('donations'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Donations')
            ->where('summary.count', 1)
            ->where('summary.open_count', 1)
            ->where('donations.0.donor_email', 'erika@example.invalid')
            ->where('donations.0.certificate', null));
    }

    public function test_money_donation_can_be_signed_stored_downloaded_and_emailed(): void
    {
        $actor = $this->signIn();
        ClubSetting::current()->update(['data' => $this->readyClubData()]);
        $this->post(route('donations.store'), $this->donationData())->assertSessionHasNoErrors();
        $donation = Donation::sole();
        $this->assertSame(12345, $donation->amount_cents);
        $this->assertMatchesRegularExpression('/^SP-2026-\d{6}$/', $donation->receipt_number);

        $this->post(route('donations.certificates.issue', $donation), ['digitally_sign' => true])->assertSessionHasNoErrors();
        $certificate = DonationCertificate::sole();
        $this->assertMatchesRegularExpression('/^ZB-2026-\d{6}$/', $certificate->certificate_number);
        $this->assertSame($actor->name, $certificate->signed_by_name);
        $this->assertSame('digital', $certificate->snapshot['signature_method']);
        $this->assertNull($certificate->snapshot['signature_image_sha256']);
        $this->assertStringStartsWith('%PDF-', $certificate->pdf());
        $this->assertStringNotContainsString('%PDF-', DB::table('donation_certificates')->value('encrypted_pdf'));
        $this->assertSame(hash('sha256', $certificate->pdf()), $certificate->pdf_sha256);

        $this->get(route('donations.certificates.document', $certificate))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$certificate->certificate_number.'.pdf"');

        Mail::fake();
        $this->post(route('donations.certificates.send', $certificate))->assertSessionHasNoErrors();
        Mail::assertSent(DonationCertificateMail::class, fn (DonationCertificateMail $mail): bool => $mail->hasTo('erika@example.invalid'));
        $this->assertDatabaseHas('donation_certificate_deliveries', ['certificate_id' => $certificate->id, 'recipient' => 'erika@example.invalid']);
        $this->assertDatabaseHas('donation_audits', ['donation_id' => $donation->id, 'event' => 'certificate_emailed']);
        $this->assertDatabaseCount('donation_audits', 4);

        $this->expectException(LogicException::class);
        $certificate->update(['signed_by_name' => 'Manipuliert']);
    }

    public function test_donation_tabs_have_canonical_routes_and_breadcrumbs(): void
    {
        $this->signIn();

        foreach ([
            'donations' => ['ledger', 'Spendenbuch'],
            'donations.create' => ['create', 'Spende anlegen'],
            'donations.open' => ['open', 'Offene Zuwendungsbestätigungen'],
        ] as $route => [$tab, $title]) {
            $this->get(route($route))->assertInertia(fn (Assert $page) => $page
                ->component('Donations')
                ->where('activeTab', $tab)
                ->where('navigationBreadcrumb.title', $title));
        }
    }

    public function test_certificate_can_use_the_signature_stored_in_the_profile(): void
    {
        $this->signIn();
        ClubSetting::current()->update(['data' => $this->readyClubData()]);
        $this->post(route('profile.signature.store'), [
            'signature' => UploadedFile::fake()->image('unterschrift.png', 500, 150),
        ])->assertSessionHasNoErrors();
        $this->post(route('donations.store'), $this->donationData())->assertSessionHasNoErrors();

        $this->post(route('donations.certificates.issue', Donation::sole()), [
            'signature_method' => 'profile',
        ])->assertSessionHasNoErrors();

        $certificate = DonationCertificate::sole();
        $this->assertSame('profile', $certificate->snapshot['signature_method']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $certificate->snapshot['signature_image_sha256']);
        $this->assertStringStartsWith('%PDF-', $certificate->pdf());
        $this->assertDatabaseHas('donation_audits', [
            'donation_id' => $certificate->donation_id,
            'event' => 'certificate_issued',
        ]);
    }

    public function test_certificate_can_use_a_signature_drawn_for_the_document(): void
    {
        $this->signIn();
        ClubSetting::current()->update(['data' => $this->readyClubData()]);
        $this->post(route('donations.store'), $this->donationData())->assertSessionHasNoErrors();
        $image = UploadedFile::fake()->image('unterschrift.png', 900, 260)->get();

        $this->post(route('donations.certificates.issue', Donation::sole()), [
            'signature_method' => 'drawn',
            'signature_data' => 'data:image/png;base64,'.base64_encode($image),
        ])->assertSessionHasNoErrors();

        $certificate = DonationCertificate::sole();
        $this->assertSame('drawn', $certificate->snapshot['signature_method']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $certificate->snapshot['signature_image_sha256']);
        $this->assertStringStartsWith('%PDF-', $certificate->pdf());
    }

    public function test_profile_signature_method_requires_a_stored_signature(): void
    {
        $this->signIn();
        ClubSetting::current()->update(['data' => $this->readyClubData()]);
        $this->post(route('donations.store'), $this->donationData())->assertSessionHasNoErrors();

        $this->post(route('donations.certificates.issue', Donation::sole()), [
            'signature_method' => 'profile',
        ])->assertSessionHasErrors('signature_method');
        $this->assertDatabaseCount('donation_certificates', 0);
    }

    public function test_material_donation_requires_ao_details_and_membership_fee_respects_configuration(): void
    {
        $this->signIn();
        ClubSetting::current()->update(['data' => $this->readyClubData()]);
        $this->post(route('donations.store'), $this->donationData([
            'donation_type' => 'material', 'description' => '', 'asset_origin' => null,
        ]))->assertSessionHasErrors(['description', 'asset_origin']);
        $this->post(route('donations.store'), $this->donationData([
            'donation_type' => 'membership_fee',
        ]))->assertSessionHasErrors('donation_type');
        $this->post(route('donations.store'), $this->donationData([
            'donation_type' => 'material',
            'description' => 'Zwei Jahre altes Ergometer, guter Zustand, Kaufpreis 900 Euro',
            'asset_origin' => 'private',
            'valuation_document_reference' => 'Rechnung vom 01.09.2024',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('donations', ['donation_type' => 'material', 'asset_origin' => 'private']);
    }

    public function test_admin_can_configure_donation_master_data_and_club_edits_preserve_it(): void
    {
        $this->signIn('admin');
        $settings = ClubSetting::current();
        $this->patch(route('configuration.donations.update'), [
            'version' => $settings->version,
            'donation_purpose_codes' => ['52-21', '52-4'],
            'contributions_tax_deductible' => false,
            'tax_privilege_notice_type' => 'section_60a_notice',
            'tax_privilege_notice_date' => '2025-05-20',
            'tax_privilege_assessment_period' => null,
            'certificate_location' => 'Berlin',
            'certificate_machine_generated_notified' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame(['52-21', '52-4'], ClubSetting::current()->data['donation_purpose_codes']);
        $this->assertDatabaseHas('configuration_changes', ['subject' => 'Spenden & Zuwendungsbestätigungen']);

        $version = ClubSetting::current()->version;
        $this->patch(route('configuration.club.update'), ['version' => $version, 'name' => 'Neuer Vereinsname'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['52-21', '52-4'], ClubSetting::current()->data['donation_purpose_codes']);
    }
}
