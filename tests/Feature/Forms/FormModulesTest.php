<?php

namespace Tests\Feature\Forms;

use App\Mail\FinanceMandateMail;
use App\Models\ClubSetting;
use App\Models\FinanceMandate;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FormModulesTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = ClubSetting::current();
        $settings->update(['data' => [
            ...$settings->data, 'name' => 'Testverein e. V.', 'street' => 'Vereinsweg 1',
            'postal_code' => '12345', 'city' => 'Berlin', 'country' => 'DE',
            'creditor_id' => 'DE98ZZZ09999999999', 'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX', 'account_holder' => 'Testverein e. V.',
        ]]);
        $this->actor = User::factory()->create(['roles' => ['mv', 'bh']]);
        $this->actingAs($this->actor);
    }

    public function test_signature_list_supports_member_and_column_selection(): void
    {
        $first = Member::factory()->create([
            'first_name' => 'Ada', 'last_name' => 'Beispiel', 'membership_type' => 'Aktiv',
            'department_role' => 'Vorstand', 'club_role' => 'Kassenprüfung', 'gender' => 'w',
            'payment_method' => 'SEPA-Lastschrift', 'street' => 'Testweg 1', 'city' => 'Berlin',
            'is_honorary' => true, 'custom_values' => ['custom_graduation_year' => 2012],
        ]);
        Member::factory()->create([
            'first_name' => 'Frida', 'last_name' => 'Ehemalig', 'joined_at' => now()->subYears(2),
            'left_at' => now()->subDay(),
        ]);
        Member::factory()->create([
            'first_name' => 'Kai', 'last_name' => 'Kontakt', 'joined_at' => null,
            'left_at' => null, 'deceased_at' => null,
        ]);
        Member::factory()->create([
            'first_name' => 'Fiona', 'last_name' => 'Zukunft', 'joined_at' => now()->addDay(),
        ]);

        $this->get(route('forms.signature-lists.index'))->assertInertia(fn (Assert $page) => $page
            ->component('forms/SignatureLists')
            ->has('members', 4)
            ->where('members.0.status', 'active')
            ->where('members.0.filter_values.membership_type', 'Aktiv')
            ->where('members.0.filter_values.department_role', 'Vorstand')
            ->where('members.0.filter_values.payment_method', 'SEPA-Lastschrift')
            ->where('members.0.filter_values.is_honorary', true)
            ->where('members.0.filter_values.custom_graduation_year', 2012)
            ->where('members.1.status', 'former')
            ->where('members.2.status', 'contacts')
            ->where('members.3.status', 'future')
            ->where('filterFields', function ($fields): bool {
                $keys = $fields->pluck('key');

                return $keys->contains('first_name')
                    && $keys->contains('membership_type')
                    && $keys->contains('payment_method')
                    && $keys->contains('custom_graduation_year')
                    && ! $keys->contains('custom_graduation')
                    && ! $keys->contains('iban');
            })
            ->where('columns', function ($columns): bool {
                $keys = $columns->pluck('key');

                return $keys->contains('member_number')
                    && $keys->contains('first_name')
                    && $keys->contains('custom_graduation_year')
                    && $keys->contains('signature')
                    && ! $keys->contains('iban')
                    && ! $keys->contains('payment_method');
            }));

        $this->post(route('forms.signature-lists.document'), [
            'title' => 'Mitgliederversammlung', 'event_date' => now()->toDateString(),
            'member_numbers' => [$first->member_number],
            'columns' => ['member_number', 'first_name', 'last_name', 'signature'],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF-', false);
    }

    public function test_mandate_is_pending_until_paper_or_digital_signature_and_can_then_be_selected(): void
    {
        $data = [
            'creation_key' => (string) Str::uuid(), 'debtor_name' => 'Ada Beispiel',
            'debtor_street' => 'Kundenweg 5', 'debtor_postal_code' => '54321', 'debtor_city' => 'Hamburg',
            'debtor_country' => 'DE', 'debtor_email' => 'ada@example.test',
            'iban' => 'DE12500105170648489890', 'mandate_type' => 'recurring',
        ];
        $this->post(route('forms.mandates.store'), $data)->assertSessionHasNoErrors();
        $mandate = FinanceMandate::query()->sole();
        $this->assertSame('pending', $mandate->status);
        $this->assertMatchesRegularExpression('/^RM-'.now()->year.'-\d{6}$/', $mandate->mandate_reference);
        $this->assertStringStartsWith('%PDF-', $mandate->pdf());
        $this->assertStringNotContainsString('Status:', $this->mandateHtml($mandate));
        $this->get(route('forms.mandates.index'))->assertInertia(fn (Assert $page) => $page
            ->where('mandates.data.0.signing_url', route('forms.mandates.sign', ['token' => $mandate->signingToken()])));

        $this->get(route('finance.invoices.create'))->assertInertia(fn (Assert $page) => $page->has('financeMandates', 0));
        Mail::fake();
        $this->post(route('forms.mandates.send', $mandate), ['recipient' => 'ada@example.test'])->assertSessionHasNoErrors();
        Mail::assertSent(FinanceMandateMail::class, fn (FinanceMandateMail $mail): bool => $mail->hasTo('ada@example.test'));

        $token = $mandate->signingToken();
        Storage::fake('local');
        $logoPath = 'branding/logo-'.Str::uuid().'.png';
        Storage::disk('local')->put($logoPath, UploadedFile::fake()->image('logo.png', 120, 40)->getContent());
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'logo_path' => $logoPath]]);
        $this->get(route('forms.mandates.sign', $token))->assertInertia(fn (Assert $page) => $page
            ->component('public/SepaMandateSign')
            ->where('mandate.status', 'pending')
            ->where('logoUrl', route('branding.logo', ['v' => $settings->version])));
        $signature = 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('signature.png', 180, 60)->getContent());
        $this->post(route('forms.mandates.sign.store', $token), [
            'signed_by_name' => 'Ada Beispiel', 'signature_data' => $signature, 'confirmed' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('signed', $mandate->fresh()->status);
        $this->assertSame('digital', $mandate->fresh()->signature_method);
        $this->assertStringContainsString('Status: <strong>Unterzeichnet</strong>', $this->mandateHtml($mandate->fresh()));
        $this->get(route('finance.invoices.create'))->assertInertia(fn (Assert $page) => $page
            ->has('financeMandates', 1)->where('financeMandates.0.id', $mandate->id));
    }

    public function test_mandate_without_email_can_be_revoked_before_signature(): void
    {
        $this->post(route('forms.mandates.store'), [
            'creation_key' => (string) Str::uuid(), 'debtor_name' => 'Ohne Mail',
            'debtor_street' => 'Weg 3', 'debtor_postal_code' => '12345', 'debtor_city' => 'Berlin',
            'debtor_country' => 'DE', 'iban' => 'DE12500105170648489890', 'mandate_type' => 'recurring',
        ])->assertSessionHasNoErrors();
        $mandate = FinanceMandate::query()->sole();
        $this->assertNull($mandate->debtor_email);
        $token = $mandate->signingToken();

        $this->post(route('forms.mandates.revoke', $mandate), [
            'reason' => 'Kontoinhaberin hat das Mandat zurückgezogen.',
        ])->assertSessionHasNoErrors();

        $mandate->refresh();
        $this->assertSame('revoked', $mandate->status);
        $this->assertNotNull($mandate->revoked_at);
        $this->assertSame($this->actor->name, $mandate->revoked_by_name);
        $this->assertSame('Kontoinhaberin hat das Mandat zurückgezogen.', $mandate->revocation_reason);
        $this->get(route('forms.mandates.sign', $token))->assertInertia(fn (Assert $page) => $page
            ->where('mandate.status', 'revoked')
            ->where('mandate.revoked_at', fn ($value): bool => is_string($value)));

        $signature = 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('signature.png', 180, 60)->getContent());
        $this->post(route('forms.mandates.sign.store', $token), [
            'signed_by_name' => 'Ohne Mail', 'signature_data' => $signature, 'confirmed' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('revoked', $mandate->fresh()->status);
        $this->get(route('finance.invoices.create'))->assertInertia(fn (Assert $page) => $page->has('financeMandates', 0));
    }

    public function test_mandate_pages_render_without_a_search_parameter(): void
    {
        $this->get(route('forms.mandates.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('forms/SepaMandates')
            ->where('activeTab', 'overview')
            ->where('search', ''));

        $this->get(route('forms.mandates.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('forms/SepaMandates')
            ->where('activeTab', 'create')
            ->where('search', ''));
    }

    public function test_paper_signature_can_be_confirmed_manually(): void
    {
        $this->post(route('forms.mandates.store'), [
            'creation_key' => (string) Str::uuid(), 'debtor_name' => 'Max Papier',
            'debtor_street' => 'Weg 2', 'debtor_postal_code' => '12345', 'debtor_city' => 'Berlin',
            'debtor_country' => 'DE', 'debtor_email' => 'max@example.test',
            'iban' => 'DE12500105170648489890', 'mandate_type' => 'one_off',
        ])->assertSessionHasNoErrors();
        $mandate = FinanceMandate::query()->sole();
        $this->post(route('forms.mandates.signed', $mandate), [
            'signed_by_name' => 'Max Papier', 'signed_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('finance_mandates', ['id' => $mandate->id, 'status' => 'signed', 'signature_method' => 'paper']);

        $this->post(route('forms.mandates.revoke', $mandate), ['reason' => 'Schriftlich widerrufen.'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('finance_mandates', [
            'id' => $mandate->id, 'status' => 'revoked', 'revocation_reason' => 'Schriftlich widerrufen.',
        ]);
    }

    private function mandateHtml(FinanceMandate $mandate): string
    {
        return view('forms.sepa-mandate', [
            'mandate' => $mandate->attributesToArray(),
            'club' => ClubSetting::current()->data,
            'logo' => null,
            'signature' => null,
        ])->render();
    }
}
