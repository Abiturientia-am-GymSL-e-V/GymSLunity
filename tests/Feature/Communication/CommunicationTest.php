<?php

declare(strict_types=1);

namespace Tests\Feature\Communication;

use App\Mail\SerialMemberMail;
use App\Models\CommunicationCampaign;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use ZipArchive;

class CommunicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00'));
        $this->actingAs(User::factory()->create(['name' => 'Test Admin', 'roles' => ['admin']]));
    }

    public function test_recipient_preview_filters_member_properties_and_custom_fields(): void
    {
        $matching = Member::factory()->create([
            'first_name' => 'Anna',
            'last_name' => 'Adler',
            'membership_type' => 'Fördermitglied',
            'department_role' => 'Trainerin',
            'club_role' => 'Vorstand',
            'gender' => 'w',
            'payment_method' => 'SEPA-Lastschrift',
            'city' => 'Berlin',
            'is_honorary' => true,
            'street' => 'Musterweg 1',
            'postal_code' => '10115',
            'joined_at' => '2026-02-01',
            'custom_values' => ['custom_graduation_year' => 2010],
        ]);
        Member::factory()->create([
            'membership_type' => 'Aktiv/ordentliches Mitglied',
            'city' => 'Hamburg',
            'gender' => 'm',
            'joined_at' => '2025-01-01',
        ]);
        Member::factory()->create(['joined_at' => null]);
        Member::factory()->create(['joined_at' => '2026-10-01']);
        Member::factory()->create(['joined_at' => '2020-01-01', 'left_at' => '2026-01-01']);

        $this->get(route('communication.mail', [
            'status' => 'all',
            'q' => 'Anna',
            'membership' => 'Fördermitglied',
            'department_role' => 'Trainerin',
            'club_role' => 'Vorstand',
            'gender' => 'w',
            'payment_method' => 'SEPA-Lastschrift',
            'city' => 'Berlin',
            'honorary' => 'yes',
            'email_status' => 'with',
            'address_status' => 'complete',
            'joined_from' => '2026-01-01',
            'joined_to' => '2026-12-31',
            'custom' => ['custom_graduation_year' => '2010'],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Kommunikation')
            ->where('activeTab', 'mail')
            ->where('navigationBreadcrumb.title', 'Serien-E-Mails')
            ->where('summary.total', 1)
            ->where('summary.with_email', 1)
            ->where('summary.complete_address', 1)
            ->has('preview.data', 1)
            ->where('preview.data.0.member_number', $matching->member_number)
            ->where('preview.data.0.name', 'Anna Adler')
            // Older single parameters are converted into field filters.
            ->where('filters.fields', fn ($fields): bool => collect($fields)->contains(
                fn (array $field): bool => $field['key'] === 'custom_graduation_year' && $field['value'] === '2010',
            )));

        $this->get(route('communication.mail', [
            'status' => 'all',
            'fields' => [
                ['key' => 'membership_type', 'value' => 'Fördermitglied'],
                ['key' => 'joined_at', 'value' => '2026-01-01', 'value_to' => '2026-12-31'],
                ['key' => 'city', 'value' => 'erl'],
                ['key' => 'department_role', 'value' => '__any__'],
            ],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('preview.data.0.member_number', $matching->member_number));
        $this->get(route('communication.mail', ['fields' => [['key' => 'iban', 'value' => 'DE']]]))
            ->assertSessionHasErrors('fields.0.key');

        $this->get(route('communication.mail', ['status' => 'contacts']))
            ->assertInertia(fn (Assert $page) => $page->where('summary.total', 1));
        $this->get(route('communication.mail', ['status' => 'future']))
            ->assertInertia(fn (Assert $page) => $page->where('summary.total', 1));
        $this->get(route('communication.mail', ['status' => 'former']))
            ->assertInertia(fn (Assert $page) => $page->where('summary.total', 1));
    }

    public function test_recipient_preview_is_paginated(): void
    {
        Member::factory()->count(30)->create();

        $this->get(route('communication.mail'))->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 30)
            ->has('preview.data', 25)
            ->where('preview.last_page', 2));
        $this->get(route('communication.mail', ['preview_page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('preview.data', 5));
    }

    public function test_campaign_can_be_reused_as_template_and_reported_as_pdf(): void
    {
        Mail::fake();
        Member::factory()->create(['email' => 'anna@example.org', 'city' => 'Berlin']);
        Member::factory()->create(['email' => 'bert@example.org', 'city' => 'Hamburg']);
        $this->post(route('communication.mail.send'), [
            'status' => 'active',
            'fields' => [['key' => 'city', 'value' => 'Berlin']],
            'subject' => 'Einladung',
            'body' => '<p>Hallo {{mitglied.name}}</p>',
            'confirmed' => '1',
        ])->assertRedirect();
        $campaign = CommunicationCampaign::query()->sole();

        $this->get(route('communication.letters', ['campaign_template' => $campaign->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('template.subject', 'Einladung')
                ->where('template.body', '<p>Hallo {{mitglied.name}}</p>')
                ->where('filters.fields.0.key', 'city')
                ->where('summary.total', 1));

        $response = $this->get(route('communication.report', $campaign));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_serial_mail_is_personalized_sent_individually_and_audited(): void
    {
        Mail::fake();
        $anna = Member::factory()->create([
            'first_name' => 'Anna',
            'last_name' => 'Adler',
            'gender' => 'w',
            'email' => 'anna@example.org',
        ]);
        $bert = Member::factory()->create([
            'first_name' => 'Bert',
            'last_name' => 'Bauer',
            'gender' => 'm',
            'email' => 'bert@example.org',
        ]);
        Member::factory()->create(['email' => null]);

        $this->post(route('communication.mail.send'), [
            'status' => 'active',
            'subject' => 'Information für {{mitglied.name}}',
            'body' => "{{mitglied.briefanrede}},\nIhre Nummer ist {{mitglied.mitgliedsnummer}}.",
            'confirmed' => '1',
        ])->assertRedirect(route('communication.mail', ['status' => 'active']));

        Mail::assertSent(SerialMemberMail::class, 2);
        Mail::assertSent(SerialMemberMail::class, fn (SerialMemberMail $mail): bool => $mail->hasTo('anna@example.org')
            && $mail->renderedSubject === 'Information für Anna Adler'
            && str_contains($mail->renderedBody, 'Sehr geehrte Frau Adler')
            && str_contains($mail->renderedBody, (string) $anna->member_number));
        Mail::assertSent(SerialMemberMail::class, fn (SerialMemberMail $mail): bool => $mail->hasTo('bert@example.org')
            && $mail->renderedSubject === 'Information für Bert Bauer'
            && str_contains($mail->renderedBody, 'Sehr geehrter Herr Bauer')
            && str_contains($mail->renderedBody, (string) $bert->member_number));

        $campaign = CommunicationCampaign::query()->sole();
        $this->assertSame(2, $campaign->recipient_count);
        $this->assertSame(1, $campaign->skipped_count);
        $this->assertSame(2, $campaign->success_count);
        $this->assertSame(0, $campaign->failure_count);
        $this->assertDatabaseCount('communication_deliveries', 2);
        $this->assertDatabaseHas('communication_deliveries', ['campaign_id' => $campaign->id, 'member_id' => $anna->id, 'status' => 'sent']);

        $this->get(route('communication.history', ['campaign' => $campaign->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('activeTab', 'history')
            ->where('selectedCampaign.id', $campaign->id)
            ->where('selectedCampaign.skipped_count', 1)
            ->has('deliveries', 2)
            ->where('deliveries.0.recipient_name', 'Anna Adler')
            ->where('deliveries.0.status', 'sent'));
    }

    public function test_unknown_placeholders_are_rejected_before_mail_is_sent(): void
    {
        Mail::fake();
        Member::factory()->create(['email' => 'member@example.org']);

        $this->from(route('communication.mail'))->post(route('communication.mail.send'), [
            'subject' => 'Hallo {{mitglied.unbekannt}}',
            'body' => 'Text',
            'confirmed' => '1',
        ])->assertRedirect(route('communication.mail'))->assertSessionHasErrors('subject');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('communication_campaigns', 0);
    }

    public function test_rich_html_is_sanitized_member_values_are_escaped_and_attachments_are_sent(): void
    {
        Mail::fake();
        Member::factory()->create([
            'first_name' => '<Admin>',
            'last_name' => 'Adler',
            'email' => 'anna@example.org',
        ]);
        $contents = "%PDF-1.4\ntest attachment\n%%EOF";

        $this->post(route('communication.mail.send'), [
            'subject' => 'Formatierte Nachricht',
            'body' => '<h2 style="text-align: center" onclick="alert(1)">Hallo <strong>{{mitglied.name}}</strong></h2><script>alert(2)</script><p><a href="javascript:alert(3)">Unsicher</a> und <em>kursiv</em></p><img src="https://example.org/tracker.png" onerror="alert(4)">',
            'confirmed' => '1',
            'attachments' => [UploadedFile::fake()->createWithContent('Information.pdf', $contents)],
        ])->assertRedirect();

        Mail::assertSent(SerialMemberMail::class, function (SerialMemberMail $mail) use ($contents): bool {
            $this->assertTrue($mail->hasTo('anna@example.org'));
            $this->assertStringContainsString('<strong>&lt;Admin&gt; Adler</strong>', $mail->renderedBody);
            $this->assertStringContainsString('<em>kursiv</em>', $mail->renderedBody);
            $this->assertStringNotContainsString('onclick', $mail->renderedBody);
            $this->assertStringNotContainsString('<script', $mail->renderedBody);
            $this->assertStringNotContainsString('javascript:', $mail->renderedBody);
            $this->assertStringNotContainsString('<img', $mail->renderedBody);
            $this->assertStringNotContainsString('<strong>', $mail->renderedText);
            $mail->assertHasAttachedData($contents, 'Information.pdf', ['mime' => 'application/pdf']);

            return true;
        });

        $campaign = CommunicationCampaign::query()->sole();
        $this->assertSame([[
            'name' => 'Information.pdf',
            'mime' => 'application/pdf',
            'size' => strlen($contents),
        ]], $campaign->attachments);
        $this->assertStringNotContainsString($contents, json_encode($campaign->attachments, JSON_THROW_ON_ERROR));
    }

    public function test_unsafe_attachment_types_are_rejected_before_sending(): void
    {
        Mail::fake();
        Member::factory()->create(['email' => 'member@example.org']);

        $this->from(route('communication.mail'))->post(route('communication.mail.send'), [
            'subject' => 'Anhang',
            'body' => '<p>Nachricht</p>',
            'confirmed' => '1',
            'attachments' => [UploadedFile::fake()->create('programm.exe', 10, 'application/x-msdownload')],
        ])->assertRedirect(route('communication.mail'))->assertSessionHasErrors('attachments.0');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('communication_campaigns', 0);
    }

    public function test_serial_letters_can_be_downloaded_as_combined_pdf(): void
    {
        Member::factory()->create([
            'first_name' => 'Anna',
            'last_name' => 'Adler',
            'street' => 'Musterweg 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
        ]);
        Member::factory()->create([
            'first_name' => 'Bert',
            'last_name' => 'Bauer',
            'street' => 'Teststraße 2',
            'postal_code' => '20095',
            'city' => 'Hamburg',
        ]);

        $image = UploadedFile::fake()->image('logo.png', 40, 20)->getContent();
        $response = $this->post(route('communication.letters.generate'), [
            'subject' => 'Brief für {{mitglied.name}}',
            'body' => '<p><strong>{{mitglied.briefanrede}}</strong>, wir informieren Sie.</p><img src="data:image/png;base64,'.base64_encode($image).'">',
            'format' => 'pdf',
            'confirmed' => '1',
        ]);

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertDatabaseHas('communication_campaigns', [
            'kind' => 'letter',
            'format' => 'pdf',
            'recipient_count' => 2,
            'success_count' => 2,
        ]);
        $this->assertDatabaseCount('communication_deliveries', 2);
    }

    public function test_embedded_mail_images_are_converted_to_inline_cid_parts(): void
    {
        Mail::fake();
        Member::factory()->create(['email' => 'member@example.org']);
        $image = UploadedFile::fake()->image('inline.png', 40, 20)->getContent();

        $this->post(route('communication.mail.send'), [
            'subject' => 'Bildtest',
            'body' => '<p>Ein Bild:</p><img src="data:image/png;base64,'.base64_encode($image).'">',
            'confirmed' => '1',
        ])->assertRedirect();

        Mail::assertSent(SerialMemberMail::class, function (SerialMemberMail $mail): bool {
            $message = new Message(new Email);
            $rendered = view('mail.serial-member', [
                'message' => $message,
                'renderedSubject' => $mail->renderedSubject,
                'renderedBody' => $mail->renderedBody,
            ])->render();
            $this->assertStringContainsString('src="cid:', $rendered);
            $this->assertStringNotContainsString('src="data:image/', $rendered);
            $this->assertCount(1, $message->getSymfonyMessage()->getAttachments());

            return true;
        });
    }

    public function test_serial_letters_can_be_downloaded_as_zip_with_individual_pdfs(): void
    {
        $member = Member::factory()->create([
            'first_name' => 'Anna',
            'last_name' => 'Adler',
            'street' => 'Musterweg 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
        ]);

        $response = $this->post(route('communication.letters.generate'), [
            'subject' => 'Einladung',
            'body' => '{{mitglied.briefanrede}}, willkommen.',
            'format' => 'zip',
            'confirmed' => '1',
        ]);

        $response->assertOk()->assertDownload();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $path = $response->baseResponse->getFile()->getPathname();
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path) === true);
        $this->assertSame(1, $archive->numFiles);
        $filename = 'Serienbrief-'.$member->member_number.'-adler-anna.pdf';
        $this->assertSame($filename, $archive->getNameIndex(0));
        $contents = $archive->getFromName($filename);
        $this->assertIsString($contents);
        $this->assertStringStartsWith('%PDF-', $contents);
        $archive->close();
        @unlink($path);
    }
}
