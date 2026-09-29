<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Mail\FinanceInvoiceMail;
use App\Models\ClubSetting;
use App\Models\FinanceInvoice;
use App\Models\FinanceMandate;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class FinanceInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = ClubSetting::current();
        $settings->update(['data' => [
            ...$settings->data,
            'name' => 'Sportverein Beispiel e. V.',
            'street' => 'Vereinsstraße 10',
            'postal_code' => '12345',
            'city' => 'Berlin',
            'country' => 'DE',
            'email' => 'rechnung@verein.test',
            'phone' => '+49 30 123456',
            'tax_number' => '27/123/45678',
            'account_holder' => 'Sportverein Beispiel e. V.',
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'bank_name' => 'Beispielbank',
        ]]);
        $this->actor = User::factory()->create(['roles' => ['bh']]);
        $this->actingAs($this->actor);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'creation_key' => (string) Str::uuid(),
            'recipient_name' => 'Ada Beispiel GmbH',
            'recipient_street' => 'Kundenweg 5',
            'recipient_postal_code' => '54321',
            'recipient_city' => 'Hamburg',
            'recipient_country' => 'DE',
            'recipient_email' => 'ada@example.test',
            'buyer_reference' => 'KUNDE-42',
            'issue_date' => Clock::todayString(),
            'service_date' => now()->subDay()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'EUR',
            'payment_method' => 'bank_transfer',
            'notes' => 'Vielen Dank für den Auftrag.',
            'debtor_iban' => '',
            'mandate_reference' => '',
            'mandate_signed_at' => '',
            'items' => [
                ['description' => 'Raummiete', 'quantity' => '2,5', 'unit_code' => 'HUR', 'price_mode' => 'net', 'unit_price' => '10,00', 'vat_rate' => '19', 'tax_exemption_reason' => ''],
                ['description' => 'Getränke', 'quantity' => '1', 'unit_code' => 'C62', 'price_mode' => 'net', 'unit_price' => '1,00', 'vat_rate' => '7', 'tax_exemption_reason' => ''],
            ],
        ];
    }

    private function mandate(string $reference = 'MANDAT-42', string $type = 'recurring'): FinanceMandate
    {
        $token = Str::random(64);
        $pdf = '%PDF-test';

        return FinanceMandate::query()->create([
            'creation_key' => (string) Str::uuid(), 'mandate_reference' => $reference,
            'debtor_name' => 'Ada Beispiel GmbH', 'debtor_street' => 'Kundenweg 5',
            'debtor_postal_code' => '54321', 'debtor_city' => 'Hamburg', 'debtor_country' => 'DE',
            'debtor_email' => 'ada@example.test', 'iban' => 'DE12500105170648489890',
            'mandate_type' => $type, 'status' => 'signed', 'mandate_text' => 'Testmandat',
            'signing_token_hash' => hash('sha256', $token), 'encrypted_signing_token' => Crypt::encryptString($token),
            'signed_at' => now()->subMonth(), 'signature_method' => 'paper', 'signed_by_name' => 'Ada Beispiel',
            'encrypted_pdf' => Crypt::encryptString(base64_encode($pdf)), 'pdf_sha256' => hash('sha256', $pdf),
            'created_by' => $this->actor->id, 'created_by_name' => $this->actor->name,
        ]);
    }

    public function test_invoice_is_calculated_archived_and_exported_as_pdf_and_xrechnung(): void
    {
        $this->post('/buchhaltung/rechnungen', $this->data())->assertSessionHasNoErrors()->assertRedirect(route('finance.invoices.index'));
        $invoice = FinanceInvoice::query()->sole();

        $this->assertSame('RW-'.now()->year.'-000001', $invoice->invoice_number);
        $this->assertSame(2600, $invoice->subtotal_cents);
        $this->assertSame(482, $invoice->tax_cents);
        $this->assertSame(3082, $invoice->total_cents);
        $this->assertSame($this->actor->name, $invoice->snapshot['created_by_name']);
        $this->assertStringStartsWith('%PDF-', $invoice->pdf());
        $this->assertStringNotContainsString('%PDF-', $invoice->getAttribute('encrypted_pdf'));
        $this->assertStringContainsString('/AFRelationship /Alternative', $invoice->pdf());
        $this->assertStringContainsString('/Subtype /application#2Fxml', $invoice->pdf());
        $this->assertStringContainsString('/EmbeddedFiles', $invoice->pdf());
        $pdfHtml = view('finance.invoice', [
            'invoice' => $invoice->snapshot,
            'logo' => null,
            'giroCode' => null,
        ])->render();
        $this->assertStringContainsString('IBAN: DE89 3704 0044 0532 0130 00', $pdfHtml);
        $this->assertStringContainsString('Beispielbank', $pdfHtml);
        $this->assertLessThan(strpos($pdfHtml, 'Beispielbank'), strpos($pdfHtml, 'IBAN:'));

        $xml = $invoice->xrechnung();
        $this->assertSame($xml, $this->embeddedXrechnung($invoice->pdf()));
        $this->assertStringContainsString('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $xml);
        $this->assertStringContainsString('<cbc:BuyerReference>KUNDE-42</cbc:BuyerReference>', $xml);
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="EUR">30.82</cbc:PayableAmount>', $xml);
        $this->assertStringContainsString('<cbc:PaymentMeansCode>58</cbc:PaymentMeansCode>', $xml);

        $this->get("/buchhaltung/rechnungen/{$invoice->id}/pdf?inline=1")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="'.$invoice->filename('pdf').'"');
        $this->get("/buchhaltung/rechnungen/{$invoice->id}/xrechnung")
            ->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee($invoice->invoice_number, false);
    }

    public function test_position_price_can_be_entered_as_gross_amount(): void
    {
        $data = $this->data();
        $data['items'] = [[
            'description' => 'Pauschale',
            'quantity' => '2,5',
            'unit_code' => 'HUR',
            'price_mode' => 'gross',
            'unit_price' => '10,00',
            'vat_rate' => '19',
            'tax_exemption_reason' => '',
        ]];

        $this->post('/buchhaltung/rechnungen', $data)->assertSessionHasNoErrors();

        $invoice = FinanceInvoice::query()->sole();
        $item = $invoice->snapshot['items'][0];
        $this->assertSame(2101, $invoice->subtotal_cents);
        $this->assertSame(399, $invoice->tax_cents);
        $this->assertSame(2500, $invoice->total_cents);
        $this->assertSame('gross', $item['price_mode']);
        $this->assertSame(1000, $item['entered_unit_price_cents']);
        $this->assertSame('8.403361', $item['unit_price_net']);
        $this->assertSame(2500, $item['gross_cents']);
        $this->assertStringContainsString('<cbc:PriceAmount currencyID="EUR">8.403361</cbc:PriceAmount>', $invoice->xrechnung());
        $this->assertStringContainsString('<cbc:LineExtensionAmount currencyID="EUR">21.01</cbc:LineExtensionAmount>', $invoice->xrechnung());
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="EUR">25.00</cbc:PayableAmount>', $invoice->xrechnung());
        $pdfHtml = view('finance.invoice', [
            'invoice' => $invoice->snapshot,
            'logo' => null,
            'giroCode' => null,
        ])->render();
        $this->assertStringContainsString('8,403361 €', $pdfHtml);
    }

    public function test_non_string_position_quantity_is_rejected_without_server_error(): void
    {
        $data = $this->data();
        $data['items'][0]['quantity'] = 2;

        $this->postJson('/buchhaltung/rechnungen', $data)->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_finance_landing_page_links_to_the_invoice_area(): void
    {
        $this->get('/buchhaltung')->assertInertia(fn (Assert $page) => $page
            ->component('finance/Overview'));

        $this->get('/buchhaltung/rechnungen')->assertInertia(fn (Assert $page) => $page
            ->component('Finance')
            ->where('invoices.total', 0));
    }

    public function test_overview_filters_open_claims_and_marks_invoice_paid(): void
    {
        $this->post('/buchhaltung/rechnungen', $this->data())->assertSessionHasNoErrors();
        $invoice = FinanceInvoice::query()->sole();

        $this->get('/buchhaltung/rechnungen?status=open&search=Ada')->assertInertia(fn (Assert $page) => $page
            ->component('Finance')
            ->where('summary.open_count', 1)
            ->where('summary.open_cents', 3082)
            ->where('invoices.total', 1)
            ->where('invoices.data.0.invoice_number', $invoice->invoice_number)
            ->missing('invoices.data.0.snapshot')
            ->missing('invoices.data.0.encrypted_pdf'));

        $this->patch("/buchhaltung/rechnungen/{$invoice->id}/bezahlt")->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame($this->actor->id, $invoice->paid_by);
        $this->get('/buchhaltung/rechnungen?status=open')->assertInertia(fn (Assert $page) => $page
            ->where('summary.open_count', 0)
            ->where('summary.paid_cents', 3082)
            ->where('invoices.total', 0));
    }

    public function test_invoice_book_filters_and_exports_the_same_scope_as_pdf(): void
    {
        $this->post('/buchhaltung/rechnungen', $this->data())->assertSessionHasNoErrors();
        $paid = FinanceInvoice::query()->sole();
        $this->patch(route('finance.invoices.paid', $paid))->assertSessionHasNoErrors();

        $older = [
            ...$this->data(),
            'creation_key' => (string) Str::uuid(),
            'recipient_name' => 'Andere Kundin',
            'recipient_email' => 'andere@example.test',
            'buyer_reference' => 'ALT-21',
            'issue_date' => now()->subDays(10)->toDateString(),
            'service_date' => now()->subDays(11)->toDateString(),
            'due_date' => now()->subDays(2)->toDateString(),
        ];
        $this->post('/buchhaltung/rechnungen', $older)->assertSessionHasNoErrors();

        $filters = [
            'search' => 'Ada',
            'from' => Clock::todayString(),
            'to' => Clock::todayString(),
            'status' => 'paid',
            'document_type' => 'invoice',
        ];
        $this->get(route('finance.invoices.index', $filters))->assertInertia(fn (Assert $page) => $page
            ->where('filters', $filters)
            ->where('invoices.total', 1)
            ->where('invoices.data.0.id', $paid->id));

        $response = $this->get(route('finance.invoices.report', $filters))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('%PDF-', false);
        $this->assertStringStartsWith('attachment; filename="rechnungsbuch-', (string) $response->headers->get('Content-Disposition'));
        $this->get(route('finance.invoices.report', ['status' => 'invalid']))->assertSessionHasErrors('status');
    }

    public function test_pdf_and_xrechnung_are_sent_together_and_delivery_is_recorded(): void
    {
        Mail::fake();
        $this->post('/buchhaltung/rechnungen', $this->data())->assertSessionHasNoErrors();
        $invoice = FinanceInvoice::query()->sole();

        $this->post("/buchhaltung/rechnungen/{$invoice->id}/versenden", ['recipient' => 'invoice@example.test'])->assertSessionHasNoErrors();

        Mail::assertSent(FinanceInvoiceMail::class, function (FinanceInvoiceMail $mail) use ($invoice): bool {
            $html = $mail->render();
            $this->assertStringContainsString('<!DOCTYPE html>', $html);
            $this->assertStringContainsString('max-width:600px', $html);
            $this->assertStringContainsString('Sportverein Beispiel e. V.', $html);
            $this->assertStringContainsString('Rechnung '.$invoice->invoice_number.' von Sportverein Beispiel e. V.', $html);
            $this->assertStringContainsString('<strong>'.$invoice->invoice_number.'</strong>', $html);

            return $mail->hasTo('invoice@example.test')
                && $mail->hasAttachedData($invoice->pdf(), $invoice->filename('pdf'), ['mime' => 'application/pdf'])
                && $mail->hasAttachedData($invoice->xrechnung(), $invoice->filename('xrechnung'), ['mime' => 'application/xml']);
        });
        $this->assertDatabaseHas('finance_invoice_deliveries', [
            'finance_invoice_id' => $invoice->id,
            'recipient' => 'invoice@example.test',
            'sent_by' => $this->actor->id,
        ]);
    }

    public function test_sepa_is_only_available_when_fully_configured(): void
    {
        $mandate = $this->mandate();
        $this->get('/buchhaltung/rechnungen/anlegen')->assertInertia(fn (Assert $page) => $page
            ->component('finance/CreateInvoice')
            ->where('paymentReadiness.bank_transfer', true)
            ->where('paymentReadiness.sepa_direct_debit', false)
            ->where('clubReadiness.ready', true)
            ->where('clubReadiness.missing', []));

        $sepa = [...$this->data(),
            'payment_method' => 'sepa_direct_debit',
            'debtor_iban' => 'DE12500105170648489890',
            'mandate_reference' => 'MANDAT-42',
            'mandate_signed_at' => now()->subMonth()->toDateString(),
            'mandate_type' => 'recurring',
            'finance_mandate_id' => $mandate->id,
        ];
        $this->post('/buchhaltung/rechnungen', $sepa)->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('finance_invoices', 0);

        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'creditor_id' => 'DE98ZZZ09999999999']]);
        $this->post('/buchhaltung/rechnungen', $sepa)->assertSessionHasNoErrors();
        $invoice = FinanceInvoice::query()->sole();
        $this->assertSame('sepa_direct_debit', $invoice->payment_method);
        $this->assertStringContainsString('<cbc:PaymentMeansCode>59</cbc:PaymentMeansCode>', $invoice->xrechnung());
        $this->assertStringContainsString('MANDAT-42', $invoice->xrechnung());
    }

    public function test_sepa_export_respects_collection_from_date_and_reuses_mandate_as_recurrent(): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'creditor_id' => 'DE98ZZZ09999999999']]);
        $mandate = $this->mandate();

        $firstCollectionDate = Clock::todayString();
        $secondCollectionDate = now()->addDays(5)->toDateString();
        $payment = [
            'payment_method' => 'sepa_direct_debit',
            'debtor_iban' => 'DE12500105170648489890',
            'mandate_reference' => 'MANDAT-42',
            'mandate_signed_at' => now()->subMonth()->toDateString(),
            'mandate_type' => 'recurring',
            'finance_mandate_id' => $mandate->id,
        ];
        $this->post('/buchhaltung/rechnungen', [
            ...$this->data(),
            ...$payment,
            'due_date' => $firstCollectionDate,
        ])->assertSessionHasNoErrors();
        $this->post('/buchhaltung/rechnungen', [
            ...$this->data(),
            ...$payment,
            'due_date' => $secondCollectionDate,
        ])->assertSessionHasNoErrors();
        [$first, $second] = FinanceInvoice::query()->orderBy('id')->get()->all();

        $this->get(route('finance.invoices.sepa.index'))->assertInertia(fn (Assert $page) => $page
            ->component('finance/SepaExport')
            ->where('sepaReady', true)
            ->where('today', $firstCollectionDate)
            ->has('invoices', 2)
            ->where('invoices.0.collection_from', $firstCollectionDate)
            ->where('invoices.1.collection_from', $secondCollectionDate));

        $xml = $this->post(route('finance.invoices.sepa.export'), [
            'ids' => [$first->id, $second->id],
            'collection_date' => $firstCollectionDate,
        ])->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('<NbOfTxs>1</NbOfTxs>', $xml);
        $this->assertStringContainsString('<Dt>'.$firstCollectionDate.'</Dt>', $xml);
        $this->assertStringContainsString('<SeqTp>FRST</SeqTp>', $xml);
        $this->assertStringContainsString('<InstdAmt Ccy="EUR">30.82</InstdAmt>', $xml);
        $this->assertStringContainsString($first->invoice_number, $xml);
        $this->assertStringNotContainsString($second->invoice_number, $xml);

        $this->assertSame('paid', $first->fresh()->status);
        $this->assertSame('FRST', $first->fresh()->mandate_sequence);
        $this->assertNotNull($first->fresh()->sepa_exported_at);
        $this->assertSame('open', $second->fresh()->status);
        $this->assertNull($second->fresh()->sepa_exported_at);
        $this->assertDatabaseHas('sepa_exports', [
            'transaction_count' => 1,
            'total_cents' => 3082,
            'collection_date' => $firstCollectionDate,
        ]);

        $this->post(route('finance.invoices.sepa.export'), [
            'ids' => [$second->id],
            'collection_date' => $firstCollectionDate,
        ])->assertSessionHasErrors('collection_date');

        $secondXml = $this->post(route('finance.invoices.sepa.export'), [
            'ids' => [$second->id],
            'collection_date' => $secondCollectionDate,
        ])->assertOk()->getContent();
        $this->assertStringContainsString('<SeqTp>RCUR</SeqTp>', $secondXml);
        $this->assertSame('paid', $second->fresh()->status);
        $this->assertSame('RCUR', $second->fresh()->mandate_sequence);
        $this->assertDatabaseCount('sepa_exports', 2);

        $pdfHtml = view('finance.invoice', [
            'invoice' => $first->snapshot,
            'logo' => null,
            'giroCode' => null,
        ])->render();
        $this->assertStringContainsString('Einzug ab', $pdfHtml);
        $this->assertStringContainsString('ab dem '.Clock::today()->format('d.m.Y'), $pdfHtml);
        $this->assertStringContainsString('Einzug ab '.$firstCollectionDate.'.', $first->xrechnung());
    }

    public function test_one_off_mandate_and_reversed_sepa_export_are_handled_correctly(): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'creditor_id' => 'DE98ZZZ09999999999']]);
        $mandate = $this->mandate('EINMAL-42', 'one_off');
        $sepa = [
            ...$this->data(),
            'payment_method' => 'sepa_direct_debit',
            'debtor_iban' => 'DE12500105170648489890',
            'mandate_reference' => 'EINMAL-42',
            'mandate_signed_at' => now()->subMonth()->toDateString(),
            'mandate_type' => 'one_off',
            'finance_mandate_id' => $mandate->id,
            'due_date' => Clock::todayString(),
        ];
        $this->post('/buchhaltung/rechnungen', $sepa)->assertSessionHasNoErrors();
        $invoice = FinanceInvoice::query()->sole();

        $xml = $this->post(route('finance.invoices.sepa.export'), [
            'ids' => [$invoice->id],
            'collection_date' => Clock::todayString(),
        ])->assertOk()->getContent();
        $this->assertStringContainsString('<SeqTp>OOFF</SeqTp>', $xml);
        $invoice->refresh();
        $this->assertSame('one_off', $invoice->mandate_type);
        $this->assertSame('OOFF', $invoice->mandate_sequence);
        $uuid = $invoice->sepa_export_uuid;

        $this->post(route('finance.invoices.sepa.reverse', $uuid), [
            'reason' => 'Datei wurde von der Bank vollständig abgelehnt.',
        ])->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame('open', $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertNull($invoice->sepa_exported_at);
        $this->assertNull($invoice->sepa_export_uuid);
        $this->assertDatabaseHas('sepa_exports', [
            'uuid' => $uuid,
            'reversal_reason' => 'Datei wurde von der Bank vollständig abgelehnt.',
        ]);
        $this->assertDatabaseHas('finance_sepa_export_items', [
            'sepa_export_uuid' => $uuid,
            'finance_invoice_id' => $invoice->id,
            'mandate_sequence' => 'OOFF',
            'status' => 'reverted',
        ]);

        $secondXml = $this->post(route('finance.invoices.sepa.export'), [
            'ids' => [$invoice->id],
            'collection_date' => Clock::todayString(),
        ])->assertOk()->getContent();
        $this->assertStringContainsString('<SeqTp>OOFF</SeqTp>', $secondXml);

        $this->post('/buchhaltung/rechnungen', [
            ...$sepa,
            'creation_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('finance_mandate_id');
        $this->assertDatabaseCount('finance_invoices', 1);
    }

    public function test_bank_import_matches_invoice_number_and_books_exact_payment(): void
    {
        $data = $this->data();
        $data['due_date'] = Clock::todayString();
        $this->post('/buchhaltung/rechnungen', $data)->assertSessionHasNoErrors();
        $invoice = FinanceInvoice::query()->sole();
        $csv = "Buchungsdatum;Betrag;Verwendungszweck\n".
            now()->format('d.m.Y').';30,82;Zahlung Rechnung '.$invoice->invoice_number."\n";

        $this->post(route('finance.invoices.bank-import.store'), [
            'csv' => UploadedFile::fake()->createWithContent('bank.csv', $csv),
        ])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(now()->toDateString(), $invoice->paid_at?->toDateString());
        $this->assertDatabaseHas('finance_bank_imports', [
            'original_name' => 'bank.csv',
            'row_count' => 1,
            'imported_count' => 1,
            'unmatched_count' => 0,
        ]);
        $this->assertDatabaseHas('finance_bank_import_rows', [
            'finance_invoice_id' => $invoice->id,
            'amount_cents' => 3082,
            'type' => 'payment',
            'status' => 'matched',
        ]);
        $this->get(route('finance.invoices.bank-import.index'))->assertInertia(fn (Assert $page) => $page
            ->component('finance/BankImport')
            ->has('recentImports', 1)
            ->has('unmatchedRows', 0));
    }

    public function test_return_debit_reopens_recurring_invoice_creates_fee_invoice_and_keeps_recurrent_sequence(): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'creditor_id' => 'DE98ZZZ09999999999']]);
        $mandate = $this->mandate('MANDAT-RUECKLAST');
        $this->post('/buchhaltung/rechnungen', [
            ...$this->data(),
            'payment_method' => 'sepa_direct_debit',
            'debtor_iban' => 'DE12500105170648489890',
            'mandate_reference' => 'MANDAT-RUECKLAST',
            'mandate_signed_at' => now()->subMonth()->toDateString(),
            'mandate_type' => 'recurring',
            'finance_mandate_id' => $mandate->id,
            'due_date' => Clock::todayString(),
        ])->assertSessionHasNoErrors();
        $invoice = FinanceInvoice::query()->sole();
        $this->post(route('finance.invoices.sepa.export'), [
            'ids' => [$invoice->id],
            'collection_date' => Clock::todayString(),
        ])->assertOk();
        $exportUuid = $invoice->fresh()->sepa_export_uuid;
        $csv = "Buchungsdatum;Betrag;Referenz;Verwendungszweck\n".
            now()->format('d.m.Y').';-33,82;'.$invoice->invoice_number.";Rücklastschrift\n";
        $this->post(route('finance.invoices.bank-import.store'), [
            'csv' => UploadedFile::fake()->createWithContent('ruecklastschrift.csv', $csv),
        ])->assertSessionHasNoErrors();
        $row = DB::table('finance_bank_import_rows')->sole();
        $this->assertSame('return_debit', $row->type);
        $this->assertSame('unmatched', $row->status);
        $this->assertSame($invoice->id, $row->finance_invoice_id);

        $this->get(route('finance.invoices.return-debits.index'))->assertInertia(fn (Assert $page) => $page
            ->component('finance/ReturnDebits')
            ->has('rows', 1)
            ->where('rows.0.finance_invoice_id', $invoice->id)
            ->has('invoices', 1));
        $this->post(route('finance.invoices.return-debits.store', $row->id), [
            'invoice_id' => $invoice->id,
            'fee_amount' => '3,00',
            'description' => 'Rücklastschriftkosten zu Rechnung '.$invoice->invoice_number,
            'due_date' => now()->addDays(14)->toDateString(),
            'vat_rate' => 0,
            'tax_exemption_reason' => 'Nicht steuerbarer Schadensersatz.',
        ])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('open', $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertNull($invoice->sepa_exported_at);
        $this->assertDatabaseHas('finance_sepa_export_items', [
            'sepa_export_uuid' => $exportUuid,
            'finance_invoice_id' => $invoice->id,
            'status' => 'returned',
        ]);
        $feeInvoice = FinanceInvoice::query()->where('id', '!=', $invoice->id)->sole();
        $this->assertSame(300, $feeInvoice->total_cents);
        $this->assertSame('bank_transfer', $feeInvoice->payment_method);
        $this->assertStringContainsString($invoice->invoice_number, $feeInvoice->snapshot['notes']);
        $this->assertDatabaseHas('finance_bank_import_rows', [
            'id' => $row->id,
            'status' => 'processed',
            'fee_invoice_id' => $feeInvoice->id,
        ]);

        $xml = $this->post(route('finance.invoices.sepa.export'), [
            'ids' => [$invoice->id],
            'collection_date' => Clock::todayString(),
        ])->assertOk()->getContent();
        $this->assertStringContainsString('<SeqTp>RCUR</SeqTp>', $xml);
    }

    public function test_tax_number_is_sufficient_and_missing_xrechnung_fields_are_named(): void
    {
        $settings = ClubSetting::current();
        $this->assertNotEmpty($settings->data['tax_number']);
        $this->assertEmpty($settings->data['vat_id'] ?? null);

        $settings->update(['data' => [...$settings->data, 'phone' => null]]);

        $this->get('/buchhaltung/rechnungen/anlegen')->assertInertia(fn (Assert $page) => $page
            ->where('clubReadiness.ready', false)
            ->where('clubReadiness.missing', ['Telefonnummer']));
        $this->post('/buchhaltung/rechnungen', $this->data())
            ->assertSessionHasErrors('club');
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_small_business_regulation_forces_zero_tax_and_adds_legal_notice(): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'small_business_regulation_enabled' => true]]);

        $this->get('/buchhaltung/rechnungen/anlegen')->assertInertia(fn (Assert $page) => $page
            ->where('smallBusinessRegulationEnabled', true)
            ->where('smallBusinessNotice', 'Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG.'));
        $this->post('/buchhaltung/rechnungen', $this->data())->assertSessionHasNoErrors();

        $invoice = FinanceInvoice::query()->sole();
        $this->assertSame(2600, $invoice->subtotal_cents);
        $this->assertSame(0, $invoice->tax_cents);
        $this->assertSame(2600, $invoice->total_cents);
        $this->assertTrue($invoice->snapshot['small_business_regulation']);
        foreach ($invoice->snapshot['items'] as $item) {
            $this->assertSame(0, $item['vat_rate']);
            $this->assertSame('E', $item['vat_category']);
            $this->assertSame('Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG.', $item['tax_exemption_reason']);
        }
        $this->assertStringContainsString('Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG.', $invoice->xrechnung());
    }

    public function test_invoice_is_cancelled_with_an_immutable_referenced_cancellation_document(): void
    {
        $this->post('/buchhaltung/rechnungen', $this->data())->assertSessionHasNoErrors();
        $original = FinanceInvoice::query()->sole();

        $this->post("/buchhaltung/rechnungen/{$original->id}/stornieren", [
            'reason' => 'Der Auftrag wurde vollständig aufgehoben.',
            'item_indices' => [0, 1],
        ])->assertSessionHasNoErrors();

        $original->refresh();
        $cancellation = FinanceInvoice::query()->where('document_type', 'cancellation')->sole();
        $this->assertSame('cancelled', $original->status);
        $this->assertNotNull($original->cancelled_at);
        $this->assertSame($this->actor->id, $original->cancelled_by);
        $this->assertSame('Der Auftrag wurde vollständig aufgehoben.', $original->cancellation_reason);
        $this->assertSame('RW-'.now()->year.'-000002', $cancellation->invoice_number);
        $this->assertSame($original->id, $cancellation->original_invoice_id);
        $this->assertSame('cancelled', $cancellation->status);
        $this->assertSame(3082, $cancellation->total_cents);
        $this->assertSame('open', $cancellation->snapshot['original_status']);
        $this->assertSame('full', $cancellation->snapshot['cancellation_scope']);
        $this->assertSame([0, 1], $cancellation->snapshot['cancelled_item_indices']);
        $this->assertSame($original->invoice_number, $cancellation->snapshot['original_invoice']['invoice_number']);
        $this->assertSame('Stornorechnung_'.$cancellation->invoice_number.'.pdf', $cancellation->filename('pdf'));
        $this->assertStringStartsWith('%PDF-', $cancellation->pdf());
        $pdfHtml = view('finance.invoice', [
            'invoice' => $cancellation->snapshot,
            'logo' => null,
            'giroCode' => null,
        ])->render();
        $this->assertStringContainsString('<h1>Stornorechnung</h1>', $pdfHtml);
        $this->assertStringContainsString('−30,82 €', $pdfHtml);
        $this->assertStringNotContainsString('Bitte überweisen Sie', $pdfHtml);

        $xml = $cancellation->xrechnung();
        $this->assertSame($xml, $this->embeddedXrechnung($cancellation->pdf()));
        $this->assertStringContainsString('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', $xml);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode>384</cbc:InvoiceTypeCode>', $xml);
        $this->assertStringContainsString('<cac:BillingReference>', $xml);
        $this->assertStringContainsString('<cbc:ID>'.$original->invoice_number.'</cbc:ID>', $xml);
        $this->assertStringContainsString('Der Auftrag wurde vollständig aufgehoben.', $xml);
        $this->assertStringContainsString('<cbc:InvoicedQuantity unitCode="HUR">-2.5</cbc:InvoicedQuantity>', $xml);
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="EUR">-30.82</cbc:PayableAmount>', $xml);
        $mail = new FinanceInvoiceMail($cancellation);
        $this->assertSame('Stornorechnung '.$cancellation->invoice_number, $mail->envelope()->subject);
        $this->assertStringContainsString('Dieser Beleg storniert die Rechnung', $mail->render());

        $this->get('/buchhaltung/rechnungen?status=cancelled')->assertInertia(fn (Assert $page) => $page
            ->where('summary.count', 1)
            ->where('summary.open_count', 0)
            ->where('summary.open_cents', 0)
            ->where('invoices.total', 2)
            ->where('invoices.data.0.document_type', 'cancellation')
            ->where('invoices.data.0.original_invoice_number', $original->invoice_number)
            ->where('invoices.data.1.document_type', 'invoice')
            ->where('invoices.data.1.status', 'cancelled'));

        $this->post("/buchhaltung/rechnungen/{$original->id}/stornieren", [
            'reason' => 'Doppelter Request',
            'item_indices' => [0, 1],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('finance_invoices', 2);
    }

    public function test_invoice_created_before_small_business_setting_can_be_cancelled(): void
    {
        $this->post('/buchhaltung/rechnungen', $this->data())->assertSessionHasNoErrors();
        $original = FinanceInvoice::query()->sole();
        $legacySnapshot = $original->snapshot;
        unset($legacySnapshot['small_business_regulation'], $legacySnapshot['small_business_notice']);
        $original->update(['snapshot' => $legacySnapshot]);

        $this->post("/buchhaltung/rechnungen/{$original->id}/stornieren", [
            'reason' => 'Altrechnung wird storniert.',
            'item_indices' => [0, 1],
        ])->assertSessionHasNoErrors();

        $cancellation = FinanceInvoice::query()->where('document_type', 'cancellation')->sole();
        $this->assertStringStartsWith('%PDF-', $cancellation->pdf());
        $this->assertStringContainsString('<cbc:InvoiceTypeCode>384</cbc:InvoiceTypeCode>', $cancellation->xrechnung());
    }

    public function test_paid_invoice_cancellation_preserves_payment_history_and_warns_about_refund(): void
    {
        $this->post('/buchhaltung/rechnungen', $this->data())->assertSessionHasNoErrors();
        $original = FinanceInvoice::query()->sole();
        $this->patch("/buchhaltung/rechnungen/{$original->id}/bezahlt")->assertSessionHasNoErrors();

        $this->post("/buchhaltung/rechnungen/{$original->id}/stornieren", [
            'reason' => 'Leistung rückabgewickelt.',
            'item_indices' => [0, 1],
        ])->assertSessionHasNoErrors()->assertInertiaFlash(
            'toast.message',
            'Stornorechnung RW-'.now()->year.'-000002 wurde erstellt und archiviert. Die ursprüngliche Rechnung war bezahlt; die Rückzahlung bitte veranlassen und anschließend in der Liste als Erstattung erfassen.',
        );

        $original->refresh();
        $this->assertSame('cancelled', $original->status);
        $this->assertNotNull($original->paid_at);
        $this->assertSame('paid', FinanceInvoice::query()->where('document_type', 'cancellation')->sole()->snapshot['original_status']);
        // Until the refund is recorded, the amount is owed back: negative open amount.
        $this->get('/buchhaltung/rechnungen')->assertInertia(fn (Assert $page) => $page
            ->where('summary.paid_cents', 3082)
            ->where('summary.open_cents', -3082)
            ->where('summary.refund_pending_cents', 3082));

        $cancellation = FinanceInvoice::query()->where('document_type', 'cancellation')->sole();
        $this->patch("/buchhaltung/rechnungen/{$original->id}/erstattet", ['refunded_at' => Clock::todayString()])
            ->assertSessionHasErrors('refund');
        $this->patch("/buchhaltung/rechnungen/{$cancellation->id}/erstattet", [
            'refunded_at' => Clock::todayString(), 'refund_reference' => 'Überweisung 42',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Überweisung 42', $cancellation->fresh()->refund_reference);
        $this->get('/buchhaltung/rechnungen')->assertInertia(fn (Assert $page) => $page
            ->where('summary.paid_cents', 0)
            ->where('summary.open_cents', 0)
            ->where('summary.refund_pending_cents', 0));
        $this->patch("/buchhaltung/rechnungen/{$cancellation->id}/erstattet", ['refunded_at' => Clock::todayString()])
            ->assertSessionHasErrors('refund');
    }

    public function test_cancellation_requires_a_reason_and_cannot_be_marked_paid(): void
    {
        $this->post('/buchhaltung/rechnungen', $this->data())->assertSessionHasNoErrors();
        $original = FinanceInvoice::query()->sole();
        $this->post("/buchhaltung/rechnungen/{$original->id}/stornieren", [
            'reason' => '',
            'item_indices' => [0, 1],
        ])->assertSessionHasErrors('reason');
        $this->post("/buchhaltung/rechnungen/{$original->id}/stornieren", [
            'reason' => 'Ohne Auswahl',
            'item_indices' => [],
        ])->assertSessionHasErrors('item_indices');
        $this->assertDatabaseCount('finance_invoices', 1);

        $this->post("/buchhaltung/rechnungen/{$original->id}/stornieren", [
            'reason' => 'Fehlbestellung',
            'item_indices' => [0, 1],
        ])->assertSessionHasNoErrors();
        $cancellation = FinanceInvoice::query()->where('document_type', 'cancellation')->sole();
        $this->patch("/buchhaltung/rechnungen/{$cancellation->id}/bezahlt")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $cancellation->fresh()->status);
    }

    public function test_individual_positions_can_be_cancelled_in_multiple_documents(): void
    {
        $this->post('/buchhaltung/rechnungen', $this->data())->assertSessionHasNoErrors();
        $original = FinanceInvoice::query()->sole();

        $this->post("/buchhaltung/rechnungen/{$original->id}/stornieren", [
            'reason' => 'Getränke wurden nicht geliefert.',
            'item_indices' => [1],
        ])->assertSessionHasNoErrors()->assertInertiaFlash(
            'toast.message',
            'Teilstornorechnung RW-'.now()->year.'-000002 wurde erstellt und archiviert.',
        );

        $original->refresh();
        $partial = FinanceInvoice::query()->where('document_type', 'cancellation')->sole();
        $this->assertSame('open', $original->status);
        $this->assertNull($original->cancelled_at);
        $this->assertSame(107, $partial->total_cents);
        $this->assertSame('partial', $partial->snapshot['cancellation_scope']);
        $this->assertSame([1], $partial->snapshot['cancelled_item_indices']);
        $this->assertCount(1, $partial->snapshot['items']);
        $this->assertSame('Getränke', $partial->snapshot['items'][0]['description']);
        $this->assertSame(1, $partial->snapshot['items'][0]['source_item_index']);
        $this->assertStringContainsString('Teilstornierung ausgewählter Positionen', $partial->xrechnung());
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="EUR">-1.07</cbc:PayableAmount>', $partial->xrechnung());
        $this->assertStringContainsString('ausgewählte Positionen aus der Rechnung', (new FinanceInvoiceMail($partial))->render());

        $this->post("/buchhaltung/rechnungen/{$original->id}/stornieren", [
            'reason' => 'Ungültige Überschneidung.',
            'item_indices' => [0, 1],
        ])->assertSessionHasErrors('item_indices');
        $this->assertDatabaseCount('finance_invoices', 2);

        $this->get('/buchhaltung/rechnungen')->assertInertia(fn (Assert $page) => $page
            ->where('summary.open_count', 1)
            ->where('summary.open_cents', 2975)
            ->where('invoices.data.1.partially_cancelled', true)
            ->has('invoices.data.1.cancellable_items', 1)
            ->where('invoices.data.1.cancellable_items.0.index', 0)
            ->where('invoices.data.1.cancellable_items.0.description', 'Raummiete'));

        $this->post("/buchhaltung/rechnungen/{$original->id}/stornieren", [
            'reason' => 'Auch die Raummiete entfällt.',
            'item_indices' => [0],
        ])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $original->fresh()->status);
        $this->assertDatabaseCount('finance_invoices', 3);
        $this->assertSame(3082, (int) FinanceInvoice::query()->where('document_type', 'cancellation')->sum('total_cents'));
    }

    public function test_validation_idempotency_permissions_and_integrity_checks(): void
    {
        $data = $this->data();
        $this->post('/buchhaltung/rechnungen', [...$data, 'items' => [[...$data['items'][0], 'vat_rate' => '0']]])->assertSessionHasErrors('items.0.tax_exemption_reason');
        $this->post('/buchhaltung/rechnungen', $data)->assertSessionHasNoErrors();
        $this->post('/buchhaltung/rechnungen', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('finance_invoices', 1);
        $invoice = FinanceInvoice::query()->sole();

        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $this->get('/buchhaltung')->assertForbidden();
        $this->get('/buchhaltung/rechnungen')->assertForbidden();
        $this->get("/buchhaltung/rechnungen/{$invoice->id}/pdf")->assertForbidden();
        $this->patch("/buchhaltung/rechnungen/{$invoice->id}/bezahlt")->assertForbidden();
        $this->post("/buchhaltung/rechnungen/{$invoice->id}/stornieren", ['reason' => 'Nicht erlaubt'])->assertForbidden();

        DB::table('finance_invoices')->where('id', $invoice->id)->update(['xrechnung_sha256' => str_repeat('0', 64)]);
        $this->expectException(LogicException::class);
        $invoice->fresh()->xrechnung();
    }

    private function embeddedXrechnung(string $pdf): string
    {
        $matched = preg_match(
            '#<</Type /EmbeddedFile.*?/Filter/FlateDecode.*?stream\n(.*?)\nendstream#s',
            $pdf,
            $matches,
        );
        $this->assertSame(1, $matched, 'Im PDF wurde kein komprimierter XML-Anhang gefunden.');
        $xml = gzuncompress($matches[1]);
        $this->assertIsString($xml, 'Der XML-Anhang im PDF konnte nicht entpackt werden.');

        return $xml;
    }
}
