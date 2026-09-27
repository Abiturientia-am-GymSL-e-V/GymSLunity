<?php

namespace Tests\Feature\Forms;

use App\Mail\ReceiptMail;
use App\Models\ClubSetting;
use App\Models\Receipt;
use App\Models\User;
use App\SelfService\FormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return [
            'creation_key' => (string) Str::uuid(), 'receipt_number' => null, 'receipt_date' => now()->toDateString(),
            'amount' => '119,00', 'currency' => 'EUR', 'vat_rate' => '19', 'vat_reason' => null,
            'payer_source' => 'other', 'payee_source' => 'club', 'payer' => "Ada Beispiel\nTeststraße 1", 'payee' => 'Manipulierter Vereinsname',
            'payer_email' => 'payer@example.com', 'payee_email' => 'club@example.com', 'purpose' => 'Teilnahme Vereinsfest',
            'signer_name' => 'Max Empfang', 'signature_method' => 'drawn',
            'signature_data' => 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('signature.png', 120, 40)->getContent()),
            'confirmed' => true, 'created_by_name' => 'Manipuliert', 'net_cents' => 1, 'amount_words' => 'Manipuliert',
        ];
    }

    private function signIn(): User
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'name' => 'Testverein', 'street' => 'Vereinsstraße 2', 'postal_code' => '12345', 'city' => 'Teststadt']]);
        $user = User::factory()->create(['roles' => ['mv']]);
        $this->actingAs($user);

        return $user;
    }

    public function test_issue_calculates_amounts_and_preserves_original_and_copy(): void
    {
        $user = $this->signIn();
        $data = $this->data();
        $this->post('/formulare/quittungen', $data)->assertSessionHasNoErrors()->assertRedirect();
        $receipt = Receipt::query()->sole();
        $this->assertSame('Q-'.now()->year.'-00001', $receipt->receipt_number);
        $this->assertSame(11900, $receipt->amount_cents);
        $this->assertSame(10000, $receipt->snapshot['net_cents']);
        $this->assertSame(1900, $receipt->snapshot['vat_cents']);
        $this->assertSame('Einhundertneunzehn und 00/100 EUR', str_replace("\u{00AD}", '', $receipt->snapshot['amount_words']));
        $this->assertSame($user->name, $receipt->snapshot['created_by_name']);
        $this->assertSame('drawn', $receipt->snapshot['signature_method']);
        $this->assertStringContainsString('Diese Quittung stellt keine Rechnung', $receipt->snapshot['notes']);
        $this->assertStringNotContainsString('Vereinfachter Spendennachweis', $receipt->snapshot['notes']);
        $this->assertStringContainsString('Testverein', $receipt->payee);
        $this->assertStringNotContainsString('Manipuliert', $receipt->payee);
        foreach (['original', 'copy'] as $edition) {
            $this->get('/formulare/quittungen/'.$receipt->id.'/pdf/'.$edition)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF-', false);
            $this->assertStringNotContainsString('%PDF-', $receipt->getAttribute('encrypted_'.$edition));
        }
        $original = $receipt->pdf('original');
        $copy = $receipt->pdf('copy');
        $this->assertNotSame($original, $copy);
        ClubSetting::current()->update(['data' => ['name' => 'Geänderter Verein']]);
        $this->assertSame($original, $receipt->fresh()->pdf('original'));
        $this->get('/formulare/quittungen/'.$receipt->id)->assertInertia(fn (Assert $page) => $page->where('receipt.receipt_number', $receipt->receipt_number)->missing('receipt.encrypted_original')->missing('receipt.signature'));
        $this->get('/formulare/quittungen/'.$receipt->id.'/pdf/unknown')->assertNotFound();
        $this->get('/formulare/quittungen/'.$receipt->id.'/pdf/original?inline=1')->assertHeader('Content-Disposition', 'inline; filename="'.$receipt->filename('original').'"');
    }

    public function test_receipt_can_be_signed_digitally_or_with_the_profile_signature(): void
    {
        $user = $this->signIn();
        $this->get('/formulare/quittungen')->assertInertia(fn (Assert $page) => $page
            ->where('hasProfileSignature', false));

        $digital = [...$this->data(), 'creation_key' => (string) Str::uuid(), 'signature_method' => 'digital'];
        unset($digital['signature_data']);
        $this->post('/formulare/quittungen', $digital)->assertSessionHasNoErrors();
        $this->assertSame('digital', Receipt::query()->latest('id')->firstOrFail()->snapshot['signature_method']);

        $profile = [...$this->data(), 'creation_key' => (string) Str::uuid(), 'signature_method' => 'profile'];
        unset($profile['signature_data']);
        $this->post('/formulare/quittungen', $profile)->assertSessionHasErrors('signature_method');
        $this->assertDatabaseCount('receipts', 1);

        $this->post(route('profile.signature.store'), [
            'signature' => UploadedFile::fake()->image('unterschrift.png', 500, 150),
        ])->assertSessionHasNoErrors();
        $this->assertTrue($user->refresh()->hasProfileSignature());
        $this->get('/formulare/quittungen')->assertInertia(fn (Assert $page) => $page
            ->where('hasProfileSignature', true));

        $this->post('/formulare/quittungen', $profile)->assertSessionHasNoErrors();
        $receipt = Receipt::query()->latest('id')->firstOrFail();
        $this->assertSame('profile', $receipt->snapshot['signature_method']);
        $this->assertStringStartsWith('%PDF-', $receipt->pdf('original'));
    }

    public function test_configured_receipt_notes_are_rendered_and_nonprofit_notes_are_appended(): void
    {
        $this->signIn();
        $settings = ClubSetting::current();
        $settings->update(['data' => [
            ...$settings->data,
            'name' => 'Sportverein Beispiel',
            'is_nonprofit' => true,
            'tax_office' => 'Berlin',
            'tax_number' => '27/123/45678',
            'tax_privilege_notice_type' => 'exemption_notice',
            'tax_privilege_notice_date' => '2025-05-20',
            'tax_privilege_assessment_period' => '2024',
            'donation_purpose_codes' => ['52-21'],
            'contributions_tax_deductible' => true,
            'receipt_notes' => 'Eigener Hinweis für {{verein.name}}.',
            'receipt_donation_notes' => 'Vereinfachter Nachweis: {{verein.tax_privilege_notice}} vom {{verein.tax_privilege_notice_date}}; {{verein.deductible_scope}}; {{verein.donation_purposes}}.',
        ]]);
        $data = [...$this->data(), 'signature_method' => 'digital'];
        unset($data['signature_data']);

        $this->post('/formulare/quittungen', $data)->assertSessionHasNoErrors();

        $notes = Receipt::query()->sole()->snapshot['notes'];
        $this->assertStringContainsString('Eigener Hinweis für Sportverein Beispiel.', $notes);
        $this->assertStringContainsString('Freistellungsbescheid (Veranlagungszeitraum 2024) vom 20.05.2025', $notes);
        $this->assertStringContainsString('Spenden und Mitgliedsbeiträge', $notes);
        $this->assertStringContainsString('Förderung des Sports', $notes);
        $this->assertStringNotContainsString('{{verein.', $notes);
    }

    public function test_receipt_note_defaults_match_the_legacy_receipt(): void
    {
        $defaults = FormTemplates::defaults();

        $this->assertStringContainsString('keine Rechnung und keine Zuwendungsbestätigung', $defaults['receipt_notes']);
        $this->assertStringContainsString('§ 267 StGB', $defaults['receipt_notes']);
        $this->assertStringContainsString('Vereinfachter Spendennachweis (§ 50 Abs. 4 Nr. 2 Buchst. b EStDV)', $defaults['receipt_donation_notes']);
        $this->assertStringContainsString('Spenden bis zu 300 Euro', $defaults['receipt_donation_notes']);
    }

    public function test_duplicate_submission_is_idempotent_and_number_collisions_are_rejected(): void
    {
        $this->signIn();
        $data = [...$this->data(), 'receipt_number' => 'ALT/42'];
        $this->post('/formulare/quittungen', $data)->assertSessionHasNoErrors();
        $this->post('/formulare/quittungen', $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('receipts', 1);
        $this->postJson('/formulare/quittungen', [...$data, 'creation_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('receipt_number');
        $this->post('/formulare/quittungen', $this->data())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('receipts', ['receipt_number' => 'Q-'.now()->year.'-00001']);
    }

    public function test_validation_rejects_invalid_money_signatures_and_missing_tax_reason(): void
    {
        $this->signIn();
        $data = $this->data();
        foreach (['-1', '0', '12.345', 'NaN', ['invalid']] as $amount) {
            $this->postJson('/formulare/quittungen', [...$data, 'amount' => $amount])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->postJson('/formulare/quittungen', [...$data, 'vat_rate' => '0'])->assertUnprocessable()->assertJsonValidationErrors('vat_reason');
        $this->postJson('/formulare/quittungen', [...$data, 'signature_data' => 'data:image/svg+xml;base64,AA=='])->assertUnprocessable()->assertJsonValidationErrors('signature_data');
        $this->postJson('/formulare/quittungen', [...$data, 'confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson('/formulare/quittungen', [...$data, 'receipt_number' => '<script>'])->assertUnprocessable()->assertJsonValidationErrors('receipt_number');
        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_zero_and_reduced_tax_rates_are_calculated_on_server(): void
    {
        $this->signIn();
        $this->post('/formulare/quittungen', [...$this->data(), 'amount' => '107,00', 'vat_rate' => '7', 'vat_reason' => 'Ermäßigter Satz für diese Zahlung'])->assertSessionHasNoErrors();
        $receipt = Receipt::query()->latest('id')->first();
        $this->assertSame(10000, $receipt->snapshot['net_cents']);
        $this->assertSame(700, $receipt->snapshot['vat_cents']);
        $this->post('/formulare/quittungen', [...$this->data(), 'amount' => '0,01', 'vat_rate' => '0', 'vat_reason' => 'Kein Umsatzsteuerausweis'])->assertSessionHasNoErrors();
        $receipt = Receipt::query()->latest('id')->first();
        $this->assertSame(1, $receipt->snapshot['net_cents']);
        $this->assertSame(0, $receipt->snapshot['vat_cents']);
    }

    public function test_receipt_routes_require_form_permission(): void
    {
        $this->get('/formulare/quittungen')->assertRedirect('/login');
        $this->signIn();
        $this->post('/formulare/quittungen', $this->data())->assertSessionHasNoErrors();
        $receipt = Receipt::query()->sole();
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $this->get('/formulare/quittungen')->assertForbidden();
        $this->post('/formulare/quittungen', $this->data())->assertForbidden();
        $this->get('/formulare/quittungen/'.$receipt->id)->assertForbidden();
        $this->get('/formulare/quittungen/'.$receipt->id.'/pdf/original')->assertForbidden();
        $this->post('/formulare/quittungen/'.$receipt->id.'/versenden', ['edition' => 'original', 'recipient' => 'test@example.com'])->assertForbidden();
    }

    public function test_receipt_can_only_be_cancelled_before_it_leaves_the_system(): void
    {
        $actor = $this->signIn();
        $pdf = '%PDF-test';
        $makeReceipt = fn (string $key, string $number): Receipt => Receipt::query()->create([
            'creation_key' => $key, 'receipt_number' => $number, 'receipt_date' => now()->toDateString(),
            'amount_cents' => 1000, 'currency' => 'EUR', 'payer' => 'Ada', 'payee' => 'Testverein', 'purpose' => 'Test',
            'snapshot' => ['receipt_number' => $number],
            'encrypted_original' => Crypt::encryptString(base64_encode($pdf)), 'original_sha256' => hash('sha256', $pdf),
            'encrypted_copy' => Crypt::encryptString(base64_encode($pdf)), 'copy_sha256' => hash('sha256', $pdf),
            'created_by' => $actor->id, 'created_by_name' => $actor->name,
        ]);
        $receipt = $makeReceipt((string) Str::uuid(), 'TEST-1');
        $this->post(route('receipts.cancel', $receipt), ['reason' => 'Fehlerhafte Angabe'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('receipts', ['id' => $receipt->id, 'cancellation_reason' => 'Fehlerhafte Angabe']);
        $this->get(route('receipts.document', [$receipt, 'original']))->assertStatus(409);

        $exported = $makeReceipt((string) Str::uuid(), 'TEST-2');
        $this->get(route('receipts.document', [$exported, 'original']))->assertOk();
        $this->post(route('receipts.cancel', $exported), ['reason' => 'Zu spät'])->assertSessionHasErrors('reason');
        $this->assertNull($exported->fresh()->cancelled_at);
    }

    public function test_email_uses_stored_edition_and_records_delivery(): void
    {
        Mail::fake();
        $actor = $this->signIn();
        $this->post('/formulare/quittungen', $this->data())->assertSessionHasNoErrors();
        Mail::assertNothingSent();
        $receipt = Receipt::query()->sole();
        foreach (['original', 'copy'] as $edition) {
            $this->post('/formulare/quittungen/'.$receipt->id.'/versenden', ['edition' => $edition, 'recipient' => $edition.'@example.com'])->assertSessionHasNoErrors();
            Mail::assertSent(ReceiptMail::class, function (ReceiptMail $mail) use ($edition, $receipt): bool {
                if ($mail->edition !== $edition || ! $mail->hasTo($edition.'@example.com')) {
                    return false;
                }
                $mail->render();

                return $mail->hasAttachedData($receipt->pdf($edition), $receipt->filename($edition), ['mime' => 'application/pdf']);
            });
            $this->assertDatabaseHas('receipt_deliveries', ['receipt_id' => $receipt->id, 'edition' => $edition, 'recipient' => $edition.'@example.com', 'sent_by' => $actor->id]);
        }
    }

    public function test_receipt_search_and_pdf_integrity_check(): void
    {
        $this->signIn();
        $this->post('/formulare/quittungen', $this->data())->assertSessionHasNoErrors();
        $this->get('/formulare/quittungen/archiv?search=Beispiel')->assertInertia(fn (Assert $page) => $page
            ->where('activeTab', 'list')
            ->where('navigationBreadcrumb.title', 'Übersicht')
            ->where('receipts.total', 1)
            ->missing('receipts.data.0.snapshot')
            ->missing('receipts.data.0.encrypted_original'));
        $this->get('/formulare/quittungen/archiv?search=unbekannt')->assertInertia(fn (Assert $page) => $page->where('receipts.total', 0));
        $receipt = Receipt::query()->sole();
        DB::table('receipts')->where('id', $receipt->id)->update(['original_sha256' => str_repeat('0', 64)]);
        $this->expectException(LogicException::class);
        $receipt->fresh()->pdf('original');
    }

    public function test_receipt_book_filters_and_exports_the_same_scope_as_pdf(): void
    {
        $this->signIn();
        $this->post('/formulare/quittungen', $this->data())->assertSessionHasNoErrors();
        $exported = Receipt::query()->sole();
        $this->get(route('receipts.document', [$exported, 'original']))->assertOk();

        $older = [
            ...$this->data(),
            'creation_key' => (string) Str::uuid(),
            'receipt_date' => now()->subDays(10)->toDateString(),
            'payer' => "Andere Person\nNebenstraße 2",
            'payer_email' => 'andere@example.com',
        ];
        $this->post('/formulare/quittungen', $older)->assertSessionHasNoErrors();

        $filters = [
            'search' => 'Ada',
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
            'status' => 'exported',
        ];
        $this->get(route('receipts.index', $filters))->assertInertia(fn (Assert $page) => $page
            ->where('filters', $filters)
            ->where('receipts.total', 1)
            ->where('receipts.data.0.id', $exported->id));

        $response = $this->get(route('receipts.report', $filters))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('%PDF-', false);
        $this->assertStringStartsWith('attachment; filename="quittungsbuch-', (string) $response->headers->get('Content-Disposition'));
        $this->get(route('receipts.report', ['status' => 'invalid']))->assertSessionHasErrors('status');
    }

    public function test_failed_mail_delivery_keeps_receipt_and_does_not_record_success(): void
    {
        $this->signIn();
        $this->post('/formulare/quittungen', $this->data())->assertSessionHasNoErrors();
        $receipt = Receipt::query()->sole();
        Mail::shouldReceive('forgetMailers')->once()->andReturnSelf();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Simulierter Versandfehler'));
        $this->post('/formulare/quittungen/'.$receipt->id.'/versenden', ['edition' => 'original', 'recipient' => 'payer@example.com'])->assertSessionHasErrors('recipient');
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('receipt_deliveries', 0);
        $this->get('/formulare/quittungen/'.$receipt->id.'/pdf/original')->assertOk();
    }

    public function test_issued_receipts_cannot_be_modified(): void
    {
        $this->signIn();
        $this->post('/formulare/quittungen', $this->data())->assertSessionHasNoErrors();
        $receipt = Receipt::query()->sole();
        $this->expectException(LogicException::class);
        $receipt->update(['amount_cents' => 1]);
    }
}
