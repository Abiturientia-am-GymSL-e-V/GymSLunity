<?php

declare(strict_types=1);

namespace App\Finance;

use App\Models\ClubSetting;
use App\Models\FinanceInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CancelFinanceInvoice
{
    public function __construct(private readonly FinanceInvoiceDocuments $documents) {}

    /**
     * @return list<array{index: int, description: string, quantity: string, unit_code: string, total_cents: int}>
     */
    public function cancellableItems(FinanceInvoice $invoice): array
    {
        if ($invoice->document_type !== 'invoice' || $invoice->status === 'cancelled') {
            return [];
        }

        $items = $invoice->snapshot['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }
        $cancelled = $this->cancelledItemIndices($invoice, count($items));

        return array_values(collect($items)
            ->map(fn (mixed $item, int $index): ?array => is_array($item) && ! in_array($index, $cancelled, true) ? [
                'index' => $index,
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => (string) ($item['quantity'] ?? ''),
                'unit_code' => (string) ($item['unit_code'] ?? ''),
                'total_cents' => (int) ($item['gross_cents'] ?? ((int) ($item['net_cents'] ?? 0) + (int) ($item['tax_cents'] ?? 0))),
            ] : null)
            ->filter()
            ->values()
            ->all());
    }

    /** @param list<int> $itemIndices */
    public function handle(FinanceInvoice $invoice, string $reason, array $itemIndices, User $actor): FinanceInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $itemIndices, $actor): FinanceInvoice {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $original = FinanceInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->can('view-finance'), 403);

            if ($original->document_type !== 'invoice') {
                throw ValidationException::withMessages(['reason' => 'Eine Stornorechnung kann nicht erneut storniert werden.']);
            }
            $items = $original->snapshot['items'] ?? [];
            if (! is_array($items) || $items === []) {
                throw ValidationException::withMessages(['item_indices' => 'Die Rechnung enthält keine stornierbaren Positionen.']);
            }

            $requested = collect($itemIndices)->map(fn (mixed $index): int => (int) $index)->unique()->sort()->values()->all();
            if ($requested === [] || collect($requested)->contains(fn (int $index): bool => ! array_key_exists($index, $items))) {
                throw ValidationException::withMessages(['item_indices' => 'Bitte mindestens eine gültige Rechnungsposition auswählen.']);
            }
            $existingCancellations = FinanceInvoice::query()
                ->where('original_invoice_id', $original->id)
                ->lockForUpdate()
                ->get();
            foreach ($existingCancellations as $existing) {
                $existingIndices = $this->indicesFromCancellation($existing, count($items));
                sort($existingIndices);
                if ($existingIndices === $requested) {
                    return $existing;
                }
            }
            $alreadyCancelled = $this->indicesFromCancellations($existingCancellations, count($items));
            if (array_intersect($requested, $alreadyCancelled) !== []) {
                throw ValidationException::withMessages(['item_indices' => 'Mindestens eine ausgewählte Position wurde bereits storniert. Bitte die Auswahl aktualisieren.']);
            }
            if ($original->status === 'cancelled') {
                throw ValidationException::withMessages(['item_indices' => 'Die Rechnung ist bereits vollständig storniert.']);
            }

            $selectedItems = collect($requested)->map(function (int $index) use ($items): array {
                /** @var array<string, mixed> $item */
                $item = $items[$index];

                return [...$item, 'source_item_index' => $index];
            })->all();
            $subtotal = (int) collect($selectedItems)->sum('net_cents');
            $tax = (int) collect($selectedItems)->sum('tax_cents');
            $allCancelled = count(array_unique([...$alreadyCancelled, ...$requested])) === count($items);
            $scope = count($requested) === count($items) ? 'full' : 'partial';

            $issueDate = now()->toDateString();
            $year = (int) now()->year;
            $next = (int) (DB::table('finance_invoice_sequences')->where('year', $year)->value('next_number') ?? 1);
            do {
                $number = 'RW-'.$year.'-'.str_pad((string) $next++, 6, '0', STR_PAD_LEFT);
            } while (FinanceInvoice::query()->where('invoice_number', $number)->exists());
            DB::table('finance_invoice_sequences')->updateOrInsert(['year' => $year], ['next_number' => $next]);

            $snapshot = [
                ...$original->snapshot,
                'invoice_number' => $number,
                'issue_date' => $issueDate,
                'due_date' => $issueDate,
                'document_type' => 'cancellation',
                'original_invoice' => [
                    'id' => $original->id,
                    'invoice_number' => $original->invoice_number,
                    'issue_date' => $original->issue_date->toDateString(),
                ],
                'original_status' => $original->status,
                'cancellation_scope' => $scope,
                'cancelled_item_indices' => $requested,
                'cancellation_reason' => trim($reason),
                'items' => $selectedItems,
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'total_cents' => $subtotal + $tax,
                'notes' => '',
                'created_by_name' => $actor->name,
                'created_at' => now()->format('d.m.Y H:i:s T'),
            ];
            $documents = $this->documents->create($snapshot, $settings->logoDataUri());
            $cancellation = FinanceInvoice::query()->create([
                'invoice_number' => $number,
                'creation_key' => (string) Str::uuid(),
                'document_type' => 'cancellation',
                'original_invoice_id' => $original->id,
                'recipient_name' => $original->recipient_name,
                'recipient_street' => $original->recipient_street,
                'recipient_postal_code' => $original->recipient_postal_code,
                'recipient_city' => $original->recipient_city,
                'recipient_country' => $original->recipient_country,
                'recipient_email' => $original->recipient_email,
                'buyer_reference' => $original->buyer_reference,
                'issue_date' => $issueDate,
                'service_date' => $original->service_date,
                'due_date' => $issueDate,
                'payment_method' => $original->payment_method,
                'currency' => $original->currency,
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'total_cents' => $subtotal + $tax,
                'status' => 'cancelled',
                'cancellation_reason' => trim($reason),
                'snapshot' => $snapshot,
                'encrypted_pdf' => Crypt::encryptString(base64_encode($documents['pdf'])),
                'pdf_sha256' => hash('sha256', $documents['pdf']),
                'encrypted_xrechnung' => Crypt::encryptString(base64_encode($documents['xrechnung'])),
                'xrechnung_sha256' => hash('sha256', $documents['xrechnung']),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ]);
            if ($allCancelled) {
                $original->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id,
                    'cancelled_by_name' => $actor->name,
                    'cancellation_reason' => trim($reason),
                ]);
            }

            return $cancellation;
        }, attempts: 3);
    }

    /** @return list<int> */
    private function cancelledItemIndices(FinanceInvoice $invoice, int $itemCount): array
    {
        $cancellations = $invoice->relationLoaded('cancellations')
            ? $invoice->cancellations
            : $invoice->cancellations()->get(['id', 'original_invoice_id', 'snapshot']);

        return $this->indicesFromCancellations($cancellations, $itemCount);
    }

    /**
     * @param  iterable<int, FinanceInvoice>  $cancellations
     * @return list<int>
     */
    private function indicesFromCancellations(iterable $cancellations, int $itemCount): array
    {
        $indices = [];
        foreach ($cancellations as $cancellation) {
            $indices = [...$indices, ...$this->indicesFromCancellation($cancellation, $itemCount)];
        }

        return array_values(collect($indices)->unique()->sort()->values()->all());
    }

    /** @return list<int> */
    private function indicesFromCancellation(FinanceInvoice $cancellation, int $itemCount): array
    {
        $indices = $cancellation->snapshot['cancelled_item_indices'] ?? null;
        if (! is_array($indices)) {
            return $itemCount === 0 ? [] : range(0, $itemCount - 1);
        }

        return array_values(collect($indices)->map(fn (mixed $index): int => (int) $index)->unique()->sort()->values()->all());
    }
}
