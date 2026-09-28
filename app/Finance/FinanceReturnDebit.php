<?php

declare(strict_types=1);

namespace App\Finance;

use App\Models\FinanceInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FinanceReturnDebit
{
    public function __construct(private readonly IssueFinanceInvoice $issue) {}

    /** @param array<string, mixed> $data */
    public function handle(int $rowId, array $data, User $actor): FinanceInvoice
    {
        $creationKey = (string) Str::uuid();

        return DB::transaction(function () use ($rowId, $data, $actor, $creationKey): FinanceInvoice {
            abort_unless($actor->fresh()?->can('view-finance'), 403);
            $row = DB::table('finance_bank_import_rows')->where('id', $rowId)->lockForUpdate()->first();
            if (! $row || $row->type !== 'return_debit') {
                throw ValidationException::withMessages(['row' => 'Die Bankbuchung ist keine Rücklastschrift.']);
            }
            if ($row->status === 'processed' && $row->fee_invoice_id) {
                return FinanceInvoice::query()->whereKey($row->fee_invoice_id)->firstOrFail();
            }
            if ($row->status !== 'unmatched' || ! $row->booking_date || (int) $row->amount_cents >= 0) {
                throw ValidationException::withMessages(['row' => 'Die Rücklastschrift ist nicht mehr bearbeitbar.']);
            }

            $original = FinanceInvoice::query()->whereKey($data['invoice_id'])->lockForUpdate()->firstOrFail();
            if ($original->document_type !== 'invoice' || $original->payment_method !== 'sepa_direct_debit'
                || $original->status !== 'paid' || $original->sepa_exported_at === null || ! $original->sepa_export_uuid) {
                throw ValidationException::withMessages(['invoice_id' => 'Bitte eine bezahlte, per SEPA exportierte Rechnung auswählen.']);
            }
            $exportItem = DB::table('finance_sepa_export_items')
                ->where('sepa_export_uuid', $original->sepa_export_uuid)
                ->where('finance_invoice_id', $original->id)
                ->lockForUpdate()
                ->first();
            if (! $exportItem || $exportItem->status !== 'exported') {
                throw ValidationException::withMessages(['invoice_id' => 'Die SEPA-Buchung wurde bereits zurückgesetzt oder als Rücklastschrift verarbeitet.']);
            }

            $mandateType = ($original->snapshot['payment']['mandate_type'] ?? $original->mandate_type) === 'one_off'
                ? 'one_off'
                : 'recurring';
            $originalUpdate = [
                'status' => 'open',
                'paid_at' => null,
                'paid_by' => null,
                'paid_by_name' => null,
            ];
            if ($mandateType === 'recurring') {
                $originalUpdate += [
                    'sepa_exported_at' => null,
                    'sepa_export_uuid' => null,
                    'sepa_collection_date' => null,
                    'mandate_sequence' => null,
                ];
            }
            $original->update($originalUpdate);
            DB::table('finance_sepa_export_items')->where('id', $exportItem->id)->update([
                'status' => 'returned',
                'returned_at' => now(),
            ]);

            $buyer = $original->snapshot['buyer'];
            $feeInvoice = $this->issue->handle([
                'creation_key' => $creationKey,
                'recipient_name' => $buyer['name'],
                'recipient_street' => $buyer['street'],
                'recipient_postal_code' => $buyer['postal_code'],
                'recipient_city' => $buyer['city'],
                'recipient_country' => $buyer['country'],
                'recipient_email' => $buyer['email'],
                'buyer_reference' => $original->buyer_reference,
                'issue_date' => now()->toDateString(),
                'service_date' => $row->booking_date,
                'due_date' => $data['due_date'],
                'currency' => 'EUR',
                'payment_method' => 'bank_transfer',
                'debtor_iban' => '',
                'mandate_reference' => '',
                'mandate_signed_at' => '',
                'mandate_type' => null,
                'notes' => 'Rücklastschrift zur Rechnung '.$original->invoice_number.' vom '.$original->issue_date->format('d.m.Y').'.',
                'items' => [[
                    'description' => $data['description'],
                    'quantity' => '1',
                    'unit_code' => 'C62',
                    'price_mode' => 'gross',
                    'unit_price' => $data['fee_amount'],
                    'vat_rate' => $data['vat_rate'],
                    'tax_exemption_reason' => $data['tax_exemption_reason'] ?? '',
                ]],
            ], $actor);

            DB::table('finance_bank_import_rows')->where('id', $rowId)->update([
                'status' => 'processed',
                'finance_invoice_id' => $original->id,
                'fee_invoice_id' => $feeInvoice->id,
                'reason' => null,
                'updated_at' => now(),
            ]);
            DB::table('finance_bank_imports')->where('id', $row->finance_bank_import_id)->update([
                'imported_count' => DB::raw('imported_count + 1'),
                'unmatched_count' => DB::raw('unmatched_count - 1'),
            ]);

            return $feeInvoice;
        }, attempts: 3);
    }
}
