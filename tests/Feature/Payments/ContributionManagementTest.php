<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Mail\ContributionInvoiceMail;
use App\Mail\DunningNoticeMail;
use App\Models\ClubSetting;
use App\Models\Contribution;
use App\Models\ContributionAccount;
use App\Models\Member;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use App\Payments\CreateContributions;
use App\Payments\DunningNotices;
use App\Payments\SepaDirectDebit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContributionManagementTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role = 'bv'): User
    {
        $user = User::factory()->create(['roles' => [$role]]);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function contributionData(array $overrides = []): array
    {
        return [
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'due_date' => '2026-10-01',
            'description' => 'Jahresbeitrag 2026',
            'amount_mode' => 'fixed',
            'amount' => '60.00',
            'membership_type' => '',
            'payment_method' => '',
            'honorary' => 'exclude',
            'tax_deductible' => false,
            ...$overrides,
        ];
    }

    public function test_payment_section_is_protected_and_exposes_overview_and_missing_mandates(): void
    {
        $this->get(route('payments'))->assertRedirect(route('login'));
        $this->signIn('bh');
        $this->get(route('payments'))->assertForbidden();
        $this->signIn();
        Member::factory()->create([
            'payment_method' => 'SEPA-Lastschrift',
            'iban' => null,
            'mandate_reference' => null,
            'mandate_signed_at' => null,
        ]);

        $this->get(route('payments'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payments')
            ->where('summary.missing_mandates', 1)
            ->where('summary.open_count', 0)
            ->has('missingMandates', 1)
            ->where('filterOptions.fields.0.key', 'custom_graduation_year')
            ->where('missingMandates.0.missing', ['IBAN', 'Mandatsreferenz', 'Mandatsdatum']));
    }

    public function test_missing_mandates_print_uses_club_name_and_logo(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27 00:05:00 UTC'));
        $this->signIn();
        Storage::fake('local');
        $logoPath = 'branding/logo-33333333-3333-3333-3333-333333333333.png';
        Storage::disk('local')->put($logoPath, UploadedFile::fake()->image('logo.png', 120, 60)->get());
        ClubSetting::current()->update(['data' => ['name' => 'Turnverein Musterstadt', 'logo_path' => $logoPath]]);
        Member::factory()->create([
            'payment_method' => 'SEPA-Lastschrift',
            'iban' => null,
            'mandate_reference' => null,
            'mandate_signed_at' => null,
        ]);

        $html = $this->get(route('payments.mandates.export', ['format' => 'print']))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'")
            ->getContent();

        $this->assertStringContainsString('<title>Turnverein Musterstadt · Fehlende SEPA-Mandate</title>', $html);
        $this->assertStringContainsString('<h1>Turnverein Musterstadt · Fehlende SEPA-Mandate</h1>', $html);
        $this->assertStringContainsString('<img class="report-logo" src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Stand 27.09.2026 02:05 CEST', $html);
    }

    public function test_contributions_are_created_for_matching_active_members_and_not_duplicated(): void
    {
        $actor = $this->signIn();
        $included = Member::factory()->create(['membership_type' => 'Aktiv', 'is_honorary' => false]);
        Member::factory()->create(['membership_type' => 'Passiv', 'is_honorary' => false]);
        Member::factory()->create(['membership_type' => 'Aktiv', 'is_honorary' => true]);
        Member::factory()->create(['membership_type' => 'Aktiv', 'left_at' => '2025-12-31']);

        $data = $this->contributionData(['membership_type' => 'Aktiv']);
        $this->post(route('payments.contributions.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('payments.contributions.store'), $data)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('contributions', 1);
        $contribution = Contribution::sole();
        $this->assertSame(6000, $contribution->amount_cents);
        $this->assertSame('open', $contribution->status);
        $this->assertSame('GYMSL-'.$included->member_number.'-'.$contribution->id, $contribution->payment_reference);
        $account = ContributionAccount::where('member_id', $included->id)->sole();
        $this->assertSame(6000, $account->balance_cents);
        $this->assertDatabaseHas('contribution_transactions', [
            'account_id' => $account->id,
            'actor_id' => $actor->id,
            'kind' => 'contribution',
            'amount_cents' => 6000,
        ]);
    }

    public function test_contributions_can_be_filtered_by_configured_member_fields(): void
    {
        $this->signIn();
        $field = MemberFieldDefinition::query()->where('key', 'custom_graduation_year')->sole();
        $field->update(['filterable' => true]);
        $included = Member::factory()->create(['custom_values' => ['custom_graduation_year' => 2012]]);
        Member::factory()->create(['custom_values' => ['custom_graduation_year' => 2013]]);

        $this->post(route('payments.contributions.store'), $this->contributionData([
            'filters' => [['key' => 'custom_graduation_year', 'value' => '2012']],
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('contributions', 1);
        $this->assertSame($included->id, Contribution::sole()->account->member_id);
    }

    public function test_bulk_contribution_creation_uses_a_bounded_number_of_select_queries(): void
    {
        $actor = User::factory()->create(['roles' => ['bv']]);
        Member::factory()->count(12)->create(['is_honorary' => false]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = app(CreateContributions::class)->handle($actor, $this->contributionData());
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $selects = collect($queries)->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'));
        $this->assertSame(['created' => 12, 'skipped' => 0], $result);
        $this->assertLessThanOrEqual(7, $selects->count(), 'Die Zahl der Leseabfragen darf nicht mit der Mitgliederzahl wachsen.');
    }

    public function test_payment_tabs_have_canonical_routes_and_breadcrumbs(): void
    {
        $this->signIn();

        foreach ([
            'payments' => ['overview', 'Übersicht'],
            'payments.mandates.index' => ['mandates', 'Mandatsverwaltung'],
            'payments.create' => ['create', 'Beiträge anlegen'],
            'payments.invoices.index' => ['invoices', 'Beitragsrechnungen'],
            'payments.dunning.index' => ['dunning', 'Mahnwesen'],
            'payments.sepa.index' => ['sepa', 'SEPA-Export'],
            'payments.bank-import.index' => ['bank', 'Bankimport'],
            'payments.return-debits.index' => ['returns', 'Rücklastschriften'],
            'payments.manual.index' => ['manual', 'Manuell buchen'],
        ] as $route => [$tab, $title]) {
            $this->get(route($route))->assertInertia(fn (Assert $page) => $page
                ->component('Payments')
                ->where('activeTab', $tab)
                ->where('navigationBreadcrumb.title', $title));
        }
    }

    public function test_dunning_lists_exports_and_reminds_members_with_open_contributions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27 10:00:00 Europe/Berlin'));
        $this->signIn();
        $settings = ClubSetting::current();
        $settings->update(['data' => [
            ...$settings->data,
            'name' => 'Turnverein Musterstadt',
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'form_of_address' => 'sie',
        ]]);
        $member = Member::factory()->create([
            'first_name' => 'Mara',
            'last_name' => 'Muster',
            'email' => 'mara@example.invalid',
            'street' => 'Vereinsweg 1',
            'postal_code' => '01234',
            'city' => 'Musterstadt',
        ]);
        $this->post(route('payments.contributions.store'), $this->contributionData([
            'due_date' => '2026-09-01',
        ]))->assertSessionHasNoErrors();

        $this->get(route('payments.dunning.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payments')
            ->where('activeTab', 'dunning')
            ->has('openDebtors', 1)
            ->where('openDebtors.0.member_number', $member->member_number)
            ->where('openDebtors.0.open_count', 1)
            ->where('openDebtors.0.open_cents', 6000)
            ->where('openDebtors.0.overdue_count', 1)
            ->where('openDebtors.0.address_ready', true));

        $csv = $this->get(route('payments.dunning.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Mara', $csv);
        $this->assertStringContainsString('60,00', $csv);

        $pdf = $this->post(route('payments.dunning.letters'), [
            'member_numbers' => [$member->member_number],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);

        $individualPdf = $this->get(route('payments.dunning.document', [
            'member' => $member->member_number,
            'format' => 'pdf',
        ]))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="zahlungserinnerung-'.$member->member_number.'.pdf"')
            ->getContent();
        $this->assertStringStartsWith('%PDF-', $individualPdf);

        $printHtml = $this->get(route('payments.dunning.document', [
            'member' => $member->member_number,
            'format' => 'print',
        ]))->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'")
            ->getContent();
        $this->assertStringContainsString('onclick="window.print()"', $printHtml);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $printHtml);

        $members = app(DunningNotices::class)->members([$member->member_number]);
        $letterHtml = app(DunningNotices::class)->html($members);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $letterHtml);
        $this->assertStringContainsString('DE89 3704 0044 0532 0130 00', $letterHtml);
        $this->assertStringContainsString('Auf Ihrem Beitragskonto', $letterHtml);
        $this->assertStringContainsString('Bitte begleichen Sie den offenen Betrag', $letterHtml);
        $this->assertStringNotContainsString('Auf deinem Beitragskonto', $letterHtml);

        $tableHtml = $this->get(route('payments.dunning.table', ['q' => 'Mara']))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'")
            ->getContent();
        $this->assertStringContainsString('Turnverein Musterstadt · Offene Beiträge', $tableHtml);
        $this->assertStringContainsString('Mara Muster', $tableHtml);

        $memberWithoutDebt = Member::factory()->create();
        $this->get(route('payments.dunning.document', [
            'member' => $memberWithoutDebt->member_number,
        ]))->assertNotFound();

        Mail::fake();
        $this->post(route('payments.dunning.send'), [
            'member_numbers' => [$member->member_number],
        ])->assertSessionHasNoErrors();
        Mail::assertSent(DunningNoticeMail::class, fn (DunningNoticeMail $mail): bool => $mail->hasTo('mara@example.invalid')
            && str_contains($mail->render(), 'data:image/svg+xml;base64,')
            && str_contains($mail->render(), 'DE89 3704 0044 0532 0130 00')
            && str_contains($mail->render(), 'Auf Ihrem Beitragskonto')
            && ! str_contains($mail->render(), 'Auf deinem Beitragskonto'));
    }

    public function test_transaction_table_can_be_exported_and_printed_with_its_filters(): void
    {
        $this->signIn();
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'name' => 'Turnverein Musterstadt']]);
        Member::factory()->create(['first_name' => 'Mara', 'last_name' => 'Muster']);
        Member::factory()->create(['first_name' => 'Bert', 'last_name' => 'Beispiel']);
        $this->post(route('payments.contributions.store'), $this->contributionData())->assertSessionHasNoErrors();
        $filters = [
            'from' => '2026-01-01',
            'to' => '2026-12-31',
            'q' => 'Mara Muster',
            'kind' => 'contribution',
            'direction' => 'charge',
        ];

        $csv = $this->get(route('payments.transactions.export', [...$filters, 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Mara Muster', $csv);
        $this->assertStringContainsString('60,00', $csv);
        $this->assertStringNotContainsString('Bert Beispiel', $csv);

        $html = $this->get(route('payments.transactions.export', [...$filters, 'format' => 'print']))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'")
            ->getContent();
        $this->assertStringContainsString('Turnverein Musterstadt · Kontobuchungen', $html);
        $this->assertStringContainsString('Mara Muster', $html);
        $this->assertStringNotContainsString('Bert Beispiel', $html);
    }

    public function test_dunning_email_rejects_selected_members_without_an_email_address(): void
    {
        $this->signIn();
        $member = Member::factory()->create(['email' => null]);
        $this->post(route('payments.contributions.store'), $this->contributionData())->assertSessionHasNoErrors();

        Mail::fake();
        $this->from(route('payments.dunning.index'))->post(route('payments.dunning.send'), [
            'member_numbers' => [$member->member_number],
        ])->assertRedirect(route('payments.dunning.index'))->assertSessionHasErrors('member_numbers');
        Mail::assertNothingSent();
    }

    public function test_manual_payment_allocates_oldest_open_contribution_and_return_fee_creates_open_item(): void
    {
        $this->signIn();
        $member = Member::factory()->create(['membership_type' => 'Aktiv']);
        $this->post(route('payments.contributions.store'), $this->contributionData())->assertSessionHasNoErrors();

        $this->post(route('payments.manual.store'), [
            'member_number' => $member->member_number,
            'amount' => '25.50',
            'booking_date' => '2026-10-02',
            'description' => 'Teilzahlung',
            'reference' => 'BANK-1',
            'direction' => 'payment',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2550, Contribution::sole()->paid_cents);
        $this->assertSame(3450, ContributionAccount::where('member_id', $member->id)->sole()->balance_cents);

        $this->post(route('payments.return-debits.store'), [
            'member_number' => $member->member_number,
            'amount' => '5.00',
            'booking_date' => '2026-10-03',
            'description' => 'Rücklastschriftgebühr',
            'reference' => 'RL-1',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contributions', ['kind' => 'return_debit_fee', 'amount_cents' => 500, 'status' => 'open']);
        $this->assertSame(3950, ContributionAccount::where('member_id', $member->id)->sole()->balance_cents);

        $this->post(route('payments.manual.store'), [
            'member_number' => $member->member_number,
            'amount' => '7.50',
            'booking_date' => '2026-10-04',
            'description' => 'Manuelle Nachforderung',
            'reference' => 'FORDERUNG-1',
            'direction' => 'charge',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contributions', ['kind' => 'manual_charge', 'amount_cents' => 750, 'status' => 'open']);
        $this->assertSame(4700, ContributionAccount::where('member_id', $member->id)->sole()->balance_cents);
    }

    public function test_invoice_can_be_generated_downloaded_and_sent(): void
    {
        $this->signIn();
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'contributions_tax_deductible' => true]]);
        Member::factory()->create(['email' => 'mitglied@example.invalid']);
        $this->post(route('payments.contributions.store'), $this->contributionData())->assertSessionHasNoErrors();
        $contribution = Contribution::sole();

        $this->post(route('payments.invoices.generate'), ['ids' => [$contribution->id], 'tax_deductible' => true])
            ->assertSessionHasNoErrors();
        $contribution->refresh();
        $this->assertTrue($contribution->tax_deductible);
        $this->assertMatchesRegularExpression('/^RE-2026-\d{6}$/', (string) $contribution->invoice_number);
        $this->get(route('payments.invoices.document', $contribution))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$contribution->invoice_number.'.pdf"');

        Mail::fake();
        $this->post(route('payments.invoices.send'), ['ids' => [$contribution->id]])->assertSessionHasNoErrors();
        Mail::assertSent(ContributionInvoiceMail::class, fn (ContributionInvoiceMail $mail): bool => $mail->hasTo('mitglied@example.invalid'));
        $this->assertNotNull($contribution->fresh()->invoice_sent_at);
    }

    public function test_transfer_invoice_contains_bank_details_girocode_and_combined_download(): void
    {
        $this->signIn();
        $settings = ClubSetting::current();
        $settings->update(['data' => [
            ...$settings->data,
            'name' => 'Sportverein Beispiel',
            'short_name' => 'SV Beispiel',
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'contribution_invoice_mail_text' => 'Individueller Rechnungstext für {{verein.name}}.',
        ]]);
        Member::factory()->count(2)->create(['payment_method' => 'Überweisung', 'email' => 'mitglied@example.invalid']);
        $this->post(route('payments.contributions.store'), $this->contributionData(['payment_method' => 'Überweisung']))->assertSessionHasNoErrors();
        $contributions = Contribution::query()->orderBy('id')->get();
        $ids = $contributions->pluck('id')->all();
        $this->post(route('payments.invoices.generate'), ['ids' => $ids])->assertSessionHasNoErrors();
        $contributions = Contribution::query()->orderBy('id')->get();

        $html = $this->get(route('payments.invoices.document', ['contribution' => $contributions->first(), 'format' => 'print']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Bitte überweise den fälligen Betrag', $html);
        $this->assertStringContainsString((string) $contributions->first()->payment_reference, $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('DE89 3704 0044 0532 0130 00', $html);

        $combined = $this->post(route('payments.invoices.combined'), ['ids' => $ids])
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $combined);

        $mail = new ContributionInvoiceMail($contributions->first());
        $this->assertStringContainsString('Individueller Rechnungstext für Sportverein Beispiel.', $mail->render());
        $this->assertStringContainsString('SV Beispiel', $mail->render());
    }

    public function test_sepa_export_creates_pain_xml_and_marks_contribution_paid(): void
    {
        $this->signIn();
        $settings = ClubSetting::current();
        $settings->update(['data' => [
            ...$settings->data,
            'name' => 'Sportverein Beispiel',
            'iban' => 'DE89370400440532013000',
            'creditor_id' => 'DE98ZZZ09999999999',
        ]]);
        Member::factory()->create([
            'payment_method' => 'SEPA-Lastschrift',
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDAT-100',
            'mandate_signed_at' => '2025-01-01',
        ]);
        $this->post(route('payments.contributions.store'), $this->contributionData(['payment_method' => 'SEPA-Lastschrift']))
            ->assertSessionHasNoErrors();
        $contribution = Contribution::sole();

        $response = $this->post(route('payments.sepa.export'), [
            'ids' => [$contribution->id],
            'collection_date' => now()->addDay()->toDateString(),
        ])->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee('urn:iso:std:iso:20022:tech:xsd:pain.008.001.08', false)
            ->assertSee('CstmrDrctDbtInitn', false)
            ->assertSee('<SeqTp>FRST</SeqTp>', false)
            ->assertSee('MANDAT-100', false);
        $this->assertSame('paid', $contribution->fresh()->status);
        $this->assertSame(6000, $contribution->fresh()->paid_cents);
        $this->assertSame('FRST', $contribution->fresh()->mandate_sequence);
        $this->assertDatabaseCount('sepa_exports', 1);
    }

    public function test_sepa_export_handles_recurring_final_and_one_off_mandates(): void
    {
        $this->signIn();
        $settings = ClubSetting::current();
        $settings->update(['data' => [
            ...$settings->data,
            'name' => 'Sportverein Beispiel',
            'iban' => 'DE89370400440532013000',
            'creditor_id' => 'DE98ZZZ09999999999',
        ]]);
        $recurring = Member::factory()->create([
            'payment_method' => 'SEPA-Lastschrift',
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDAT-RCUR',
            'mandate_signed_at' => '2025-01-01',
            'mandate_type' => 'recurring',
            'left_at' => '2027-12-31',
        ]);

        foreach ([
            ['2026-01-01', '2026-12-31', 'Beitrag 2026', 'FRST'],
            ['2027-01-01', '2027-06-30', 'Beitrag H1 2027', 'RCUR'],
            ['2027-07-01', '2027-12-31', 'Beitrag H2 2027', 'FNAL'],
        ] as [$start, $end, $description, $sequence]) {
            $this->post(route('payments.contributions.store'), $this->contributionData([
                'period_start' => $start,
                'period_end' => $end,
                'due_date' => $end,
                'description' => $description,
                'payment_method' => 'SEPA-Lastschrift',
            ]))->assertSessionHasNoErrors();
            $contribution = Contribution::query()->where('description', $description)->sole();
            $this->post(route('payments.sepa.export'), [
                'ids' => [$contribution->id],
                'collection_date' => now()->addDay()->toDateString(),
            ])->assertOk()->assertSee('<SeqTp>'.$sequence.'</SeqTp>', false);
            $this->assertSame($sequence, $contribution->fresh()->mandate_sequence);
        }

        $oneOff = Member::factory()->create([
            'payment_method' => 'SEPA-Lastschrift',
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDAT-OOFF',
            'mandate_signed_at' => '2026-01-01',
            'mandate_type' => 'one_off',
        ]);
        $account = ContributionAccount::query()->where('member_id', $oneOff->id)->sole();
        $oneOffContribution = Contribution::query()->create([
            'account_id' => $account->id,
            'kind' => 'contribution',
            'description' => 'Einmaliger Beitrag',
            'amount_cents' => 1000,
            'paid_cents' => 0,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'due_date' => '2026-10-01',
            'payment_method' => 'SEPA-Lastschrift',
            'status' => 'open',
        ]);
        $this->post(route('payments.sepa.export'), [
            'ids' => [$oneOffContribution->id],
            'collection_date' => now()->addDay()->toDateString(),
        ])->assertOk()->assertSee('<SeqTp>OOFF</SeqTp>', false);
        $this->assertSame('OOFF', $oneOffContribution->fresh()->mandate_sequence);
    }

    public function test_sepa_sequence_checks_are_batched_for_all_selected_contributions(): void
    {
        $actor = User::factory()->create(['roles' => ['bv']]);
        $settings = ClubSetting::current();
        $settings->update(['data' => [
            ...$settings->data,
            'name' => 'Sportverein Beispiel',
            'iban' => 'DE89370400440532013000',
            'creditor_id' => 'DE98ZZZ09999999999',
        ]]);
        Member::factory()->count(6)->sequence(fn ($sequence): array => [
            'payment_method' => 'SEPA-Lastschrift',
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDAT-BATCH-'.$sequence->index,
            'mandate_signed_at' => '2025-01-01',
        ])->create();
        app(CreateContributions::class)->handle($actor, $this->contributionData(['payment_method' => 'SEPA-Lastschrift']));
        $ids = Contribution::query()->orderBy('id')->pluck('id')->all();

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(SepaDirectDebit::class)->export($ids, now()->addDay()->toDateString(), $actor);
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
        }

        $priorUsageQueries = $queries->filter(fn (string $query): bool => str_contains($query, 'from ')
            && str_contains($query, 'contributions')
            && str_contains($query, 'sepa_exported_at')
            && str_contains($query, 'mandate_reference'));
        $futureContributionQueries = $queries->filter(fn (string $query): bool => str_contains($query, 'from ')
            && str_contains($query, 'contributions')
            && str_contains($query, 'payment_method')
            && str_contains($query, 'status'));
        $this->assertCount(1, $priorUsageQueries, 'Die Mandatsverwendung muss für die gesamte Auswahl gemeinsam geladen werden. Abfragen: '.$queries->join(' | '));
        $this->assertCount(1, $futureContributionQueries, 'Offene Folgebeiträge müssen für die gesamte Auswahl gemeinsam geladen werden.');
    }

    public function test_bank_csv_import_matches_member_and_rejects_duplicate_file(): void
    {
        $this->signIn();
        $member = Member::factory()->create();
        $csv = "Buchungsdatum;Betrag;Mitgliedsnummer;Verwendungszweck\n02.10.2026;20,00;{$member->member_number};Beitrag\n";
        $file = UploadedFile::fake()->createWithContent('umsatz.csv', $csv);
        $this->post(route('payments.bank-import'), ['csv' => $file])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contribution_transactions', ['kind' => 'bank_payment', 'amount_cents' => -2000]);
        $this->assertSame(-2000, ContributionAccount::where('member_id', $member->id)->sole()->balance_cents);

        $duplicate = UploadedFile::fake()->createWithContent('nochmal.csv', $csv);
        $this->post(route('payments.bank-import'), ['csv' => $duplicate])->assertSessionHasErrors('csv');
        $this->assertDatabaseCount('payment_imports', 1);
    }

    public function test_bank_import_uses_payment_reference_allows_manual_assignment_and_reopens_return_debit(): void
    {
        $this->signIn();
        $member = Member::factory()->create();
        $this->post(route('payments.contributions.store'), $this->contributionData())->assertSessionHasNoErrors();
        $contribution = Contribution::sole();

        $payment = "Buchungsdatum;Betrag;Verwendungszweck\n02.10.2026;60,00;{$contribution->payment_reference}\n";
        $this->post(route('payments.bank-import'), [
            'csv' => UploadedFile::fake()->createWithContent('zahlung.csv', $payment),
        ])->assertSessionHasNoErrors();
        $this->assertSame('paid', $contribution->fresh()->status);

        $returnDebit = "Buchungsdatum;Betrag;Verwendungszweck\n03.10.2026;-60,00;Rücklastschrift {$contribution->payment_reference}\n";
        $this->post(route('payments.bank-import'), [
            'csv' => UploadedFile::fake()->createWithContent('ruecklastschrift.csv', $returnDebit),
        ])->assertSessionHasNoErrors();
        $this->assertSame('open', $contribution->fresh()->status);
        $this->assertSame(0, $contribution->fresh()->paid_cents);
        $this->assertDatabaseHas('contribution_transactions', [
            'contribution_id' => $contribution->id,
            'kind' => 'bank_return_debit',
            'amount_cents' => 6000,
        ]);

        $unmatched = "Buchungsdatum;Betrag;Verwendungszweck\n04.10.2026;10,00;Nicht automatisch erkennbar\n";
        $this->post(route('payments.bank-import'), [
            'csv' => UploadedFile::fake()->createWithContent('offen.csv', $unmatched),
        ])->assertSessionHasNoErrors();
        $row = DB::table('payment_import_rows')->where('status', 'unmatched')->sole();
        $this->post(route('payments.bank-import.assign', ['paymentImport' => $row->payment_import_id, 'row' => $row->id]), [
            'member_number' => $member->member_number,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payment_import_rows', ['id' => $row->id, 'status' => 'matched', 'member_id' => $member->id]);
        $this->assertDatabaseHas('contribution_transactions', ['kind' => 'bank_payment', 'amount_cents' => -1000]);
    }

    public function test_member_detail_contains_contribution_account_above_history_data(): void
    {
        $this->signIn('admin');
        $member = Member::factory()->create();
        $this->post(route('payments.contributions.store'), $this->contributionData())->assertSessionHasNoErrors();

        $this->get(route('members.show', $member->member_number))->assertInertia(fn (Assert $page) => $page
            ->where('contributionAccount.balance_cents', 6000)
            ->has('contributionAccount.transactions', 1));
    }
}
