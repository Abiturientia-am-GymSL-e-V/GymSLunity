<?php

declare(strict_types=1);

namespace App\Finance;

use App\Models\ClubSetting;
use App\Models\FinanceInvoice;
use App\Models\User;
use App\Payments\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IssueFinanceInvoice
{
    public const SMALL_BUSINESS_NOTICE = 'Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG.';

    public function __construct(private readonly FinanceInvoiceDocuments $documents) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, User $actor): FinanceInvoice
    {
        return DB::transaction(function () use ($data, $actor): FinanceInvoice {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->can('view-finance'), 403);
            $existing = FinanceInvoice::query()->where('creation_key', $data['creation_key'])->first();
            if ($existing) {
                abort_unless($existing->created_by === $actor->id, 403);

                return $existing;
            }

            $seller = $this->seller($settings->data);
            $this->ensurePaymentReady($data, $seller);
            $smallBusinessRegulation = (bool) ($settings->data['small_business_regulation_enabled'] ?? false);
            $year = (int) substr($data['issue_date'], 0, 4);
            $next = (int) (DB::table('finance_invoice_sequences')->where('year', $year)->value('next_number') ?? 1);
            do {
                $number = 'RW-'.$year.'-'.str_pad((string) $next++, 6, '0', STR_PAD_LEFT);
            } while (FinanceInvoice::query()->where('invoice_number', $number)->exists());
            DB::table('finance_invoice_sequences')->updateOrInsert(['year' => $year], ['next_number' => $next]);

            [$items, $subtotal, $tax] = $this->items($data['items'], $smallBusinessRegulation);
            $snapshot = [
                'invoice_number' => $number,
                ...Arr::only($data, ['issue_date', 'service_date', 'due_date', 'currency', 'buyer_reference', 'payment_method', 'notes']),
                'seller' => $seller,
                'buyer' => [
                    'name' => trim($data['recipient_name']),
                    'street' => trim($data['recipient_street']),
                    'postal_code' => trim($data['recipient_postal_code']),
                    'city' => trim($data['recipient_city']),
                    'country' => strtoupper($data['recipient_country']),
                    'email' => strtolower(trim($data['recipient_email'])),
                ],
                'payment' => Arr::only($data, ['debtor_iban', 'mandate_reference', 'mandate_signed_at', 'mandate_type']),
                'items' => $items,
                'document_type' => 'invoice',
                'small_business_regulation' => $smallBusinessRegulation,
                'small_business_notice' => $smallBusinessRegulation ? self::SMALL_BUSINESS_NOTICE : '',
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'total_cents' => $subtotal + $tax,
                'created_by_name' => $actor->name,
                'created_at' => now()->format('d.m.Y H:i:s T'),
            ];
            $documents = $this->documents->create($snapshot, $settings->logoDataUri());

            return FinanceInvoice::query()->create([
                'invoice_number' => $number,
                'creation_key' => $data['creation_key'],
                'document_type' => 'invoice',
                'recipient_name' => $snapshot['buyer']['name'],
                'recipient_street' => $snapshot['buyer']['street'],
                'recipient_postal_code' => $snapshot['buyer']['postal_code'],
                'recipient_city' => $snapshot['buyer']['city'],
                'recipient_country' => $snapshot['buyer']['country'],
                'recipient_email' => $snapshot['buyer']['email'],
                'buyer_reference' => $snapshot['buyer_reference'],
                ...Arr::only($snapshot, ['issue_date', 'service_date', 'due_date', 'payment_method', 'currency', 'subtotal_cents', 'tax_cents', 'total_cents']),
                'mandate_type' => $data['payment_method'] === 'sepa_direct_debit' ? $data['mandate_type'] : null,
                'finance_mandate_id' => $data['payment_method'] === 'sepa_direct_debit' ? $data['finance_mandate_id'] : null,
                'status' => 'open',
                'snapshot' => $snapshot,
                'encrypted_pdf' => Crypt::encryptString(base64_encode($documents['pdf'])),
                'pdf_sha256' => hash('sha256', $documents['pdf']),
                'encrypted_xrechnung' => Crypt::encryptString(base64_encode($documents['xrechnung'])),
                'xrechnung_sha256' => hash('sha256', $documents['xrechnung']),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ]);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $club
     * @return array<string, string>
     */
    private function seller(array $club): array
    {
        $seller = collect(['name', 'street', 'postal_code', 'city', 'country', 'email', 'phone', 'tax_number', 'vat_id', 'account_holder', 'iban', 'bic', 'bank_name', 'creditor_id'])
            ->mapWithKeys(fn (string $key): array => [$key => trim((string) ($club[$key] ?? ''))])->all();
        $labels = [
            'name' => 'Vereinsname',
            'street' => 'Straße und Hausnummer',
            'postal_code' => 'Postleitzahl',
            'city' => 'Ort',
            'country' => 'Land',
            'email' => 'E-Mail-Adresse',
            'phone' => 'Telefonnummer',
        ];
        $missing = collect($labels)->filter(fn (string $label, string $key): bool => $seller[$key] === '')->values()->all();
        if ($seller['tax_number'] === '' && $seller['vat_id'] === '') {
            $missing[] = 'Steuernummer oder USt-IdNr.';
        }
        if ($missing !== []) {
            throw ValidationException::withMessages(['club' => 'Für die XRechnung fehlen in der Vereinskonfiguration: '.implode(', ', $missing).'. Als Steuerangabe genügt entweder die Steuernummer oder die USt-IdNr.']);
        }
        $seller['country'] = strtoupper($seller['country']);

        return $seller;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $seller
     */
    private function ensurePaymentReady(array $data, array $seller): void
    {
        if ($data['payment_method'] === 'bank_transfer' && ($seller['iban'] === '' || $seller['account_holder'] === '')) {
            throw ValidationException::withMessages(['payment_method' => 'Für Überweisungen müssen Kontoinhaber und IBAN in der Vereinskonfiguration hinterlegt sein.']);
        }
        // Same fields as ClubSettings::SEPA_FIELDS, checked on the locked row.
        if ($data['payment_method'] === 'sepa_direct_debit' && ($seller['name'] === '' || $seller['iban'] === '' || $seller['creditor_id'] === '')) {
            throw ValidationException::withMessages(['payment_method' => 'SEPA-Lastschrift ist erst mit Vereinsname, IBAN und Gläubiger-ID in der Vereinskonfiguration verfügbar.']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rawItems
     * @return array{list<array<string, mixed>>, int, int}
     */
    private function items(array $rawItems, bool $smallBusinessRegulation): array
    {
        $items = [];
        $subtotal = 0;
        $tax = 0;
        foreach ($rawItems as $raw) {
            $quantity = str_replace(',', '.', trim((string) $raw['quantity']));
            [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
            $quantityThousandths = ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
            $unitPrice = Money::cents($raw['unit_price']);
            $rate = $smallBusinessRegulation ? 0 : (int) $raw['vat_rate'];
            $priceMode = (string) $raw['price_mode'];
            $lineAmount = intdiv(($quantityThousandths * $unitPrice) + 500, 1000);
            if ($priceMode === 'gross') {
                $gross = $lineAmount;
                $net = intdiv(($gross * 100) + intdiv(100 + $rate, 2), 100 + $rate);
                $itemTax = $gross - $net;
                $unitPriceNet = $rate === 0
                    ? number_format($unitPrice / 100, 2, '.', '')
                    : rtrim(rtrim(number_format($unitPrice / (100 + $rate), 6, '.', ''), '0'), '.');
                $unitPriceNetCents = intdiv(($unitPrice * 100) + intdiv(100 + $rate, 2), 100 + $rate);
            } else {
                $net = $lineAmount;
                $itemTax = intdiv(($net * $rate) + 50, 100);
                $gross = $net + $itemTax;
                $unitPriceNet = number_format($unitPrice / 100, 2, '.', '');
                $unitPriceNetCents = $unitPrice;
            }
            $subtotal += $net;
            $tax += $itemTax;
            $items[] = [
                'description' => trim($raw['description']),
                'quantity' => rtrim(rtrim(number_format($quantityThousandths / 1000, 3, '.', ''), '0'), '.'),
                'unit_code' => $raw['unit_code'],
                'price_mode' => $priceMode,
                'entered_unit_price_cents' => $unitPrice,
                'unit_price_cents' => $unitPriceNetCents,
                'unit_price_net' => $unitPriceNet,
                'vat_rate' => $rate,
                'vat_category' => $rate === 0 ? 'E' : 'S',
                'tax_exemption_reason' => $smallBusinessRegulation
                    ? self::SMALL_BUSINESS_NOTICE
                    : ($rate === 0 ? trim((string) $raw['tax_exemption_reason']) : ''),
                'net_cents' => $net,
                'tax_cents' => $itemTax,
                'gross_cents' => $gross,
            ];
        }

        return [$items, $subtotal, $tax];
    }
}
