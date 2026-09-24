<?php

namespace Tests\Feature\Payments;

use App\Mail\ContributionInvoiceMail;
use App\Models\ClubSetting;
use App\Models\Contribution;
use App\Models\ContributionAccount;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
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
            ->where('missingMandates.0.missing', ['IBAN', 'Mandatsreferenz', 'Mandatsdatum']));
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
        $account = ContributionAccount::where('member_id', $included->id)->sole();
        $this->assertSame(6000, $account->balance_cents);
        $this->assertDatabaseHas('contribution_transactions', [
            'account_id' => $account->id,
            'actor_id' => $actor->id,
            'kind' => 'contribution',
            'amount_cents' => 6000,
        ]);
    }

    public function test_payment_tabs_have_canonical_routes_and_breadcrumbs(): void
    {
        $this->signIn();

        foreach ([
            'payments' => ['overview', 'Übersicht'],
            'payments.mandates.index' => ['mandates', 'Mandatsverwaltung'],
            'payments.create' => ['create', 'Beiträge anlegen'],
            'payments.invoices.index' => ['invoices', 'Beitragsrechnungen'],
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
        $response->assertSee('CstmrDrctDbtInitn', false)->assertSee('MANDAT-100', false);
        $this->assertSame('paid', $contribution->fresh()->status);
        $this->assertSame(6000, $contribution->fresh()->paid_cents);
        $this->assertDatabaseCount('sepa_exports', 1);
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
