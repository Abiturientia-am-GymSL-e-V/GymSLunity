<?php

declare(strict_types=1);

namespace App\Receipts;

use App\Documents\SignatureImage;
use App\Models\ClubSetting;
use App\Models\Receipt;
use App\Models\User;
use App\Payments\Money;
use App\SelfService\FormTemplates;
use App\Support\FormOfAddress;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use NumberFormatter;
use RuntimeException;

final class IssueReceipt
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, User $actor, ?string $ip): Receipt
    {
        return DB::transaction(function () use ($data, $actor, $ip): Receipt {
            // Serialize number allocation, including explicitly supplied numbers.
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->can('view-forms'), 403);
            $existing = Receipt::query()->where('creation_key', $data['creation_key'])->first();
            if ($existing) {
                abort_unless($existing->created_by === $actor->id, 403);

                return $existing;
            }
            $number = $data['receipt_number'] ?? null;
            if ($number && Receipt::query()->where('receipt_number', $number)->exists()) {
                throw ValidationException::withMessages(['receipt_number' => 'Diese Quittungsnummer ist bereits vergeben.']);
            }
            if (! $number) {
                $year = (int) substr($data['receipt_date'], 0, 4);
                $next = (int) (DB::table('receipt_sequences')->where('year', $year)->value('next_number') ?? 1);
                do {
                    $number = 'Q-'.$year.'-'.str_pad((string) $next++, 5, '0', STR_PAD_LEFT);
                } while (Receipt::query()->where('receipt_number', $number)->exists());
                DB::table('receipt_sequences')->updateOrInsert(['year' => $year], ['next_number' => $next]);
            }
            $signature = null;
            if ($data['signature_method'] === 'profile') {
                $signature = $actor->profileSignature();
                if (! is_string($signature)) {
                    throw ValidationException::withMessages(['signature_method' => FormOfAddress::choose('In deinem Profil ist noch keine Unterschrift hinterlegt.', 'In Ihrem Profil ist noch keine Unterschrift hinterlegt.')]);
                }
            } elseif ($data['signature_method'] === 'drawn') {
                try {
                    $signature = SignatureImage::fromDataUrl($data['signature_data']);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['signature_data' => $exception->getMessage()]);
                }
            }
            $gross = Money::cents($data['amount']);
            // Positive amounts, integer arithmetic, half-up rounding to the minor unit.
            $rate = (int) $data['vat_rate'];
            $net = intdiv($gross * 100 + intdiv(100 + $rate, 2), 100 + $rate);
            $words = (new NumberFormatter('de', NumberFormatter::SPELLOUT))->format(intdiv($gross, 100));
            if ($words === false) {
                throw new RuntimeException('Der Betrag konnte nicht in Worte umgewandelt werden.');
            }
            $club = Arr::only($settings->data, ['name', 'street', 'postal_code', 'city', 'email', 'register_number', 'tax_number', 'vat_id']);
            $clubAddress = implode("\n", array_filter([$club['name'] ?? '', $club['street'] ?? '', trim(($club['postal_code'] ?? '').' '.($club['city'] ?? ''))]));
            if (($data['payer_source'] === 'club' || $data['payee_source'] === 'club') && empty($club['name'])) {
                throw ValidationException::withMessages(['payee' => 'Bitte zuerst den Vereinsnamen in der Konfiguration hinterlegen.']);
            }
            $payer = $data['payer_source'] === 'club' ? $clubAddress : $data['payer'];
            $payee = $data['payee_source'] === 'club' ? $clubAddress : $data['payee'];
            $texts = FormTemplates::rendered();
            $notes = $texts['receipt_notes'];
            if ($settings->data['is_nonprofit'] ?? false) {
                $notes .= "\n\n".$texts['receipt_donation_notes'];
            }
            $snapshot = [
                ...Arr::only($data, ['currency', 'purpose', 'receipt_date', 'signer_name', 'payer_email', 'payee_email']),
                'receipt_number' => $number, 'payer' => $payer, 'payee' => $payee,
                'amount_cents' => $gross, 'net_cents' => $net, 'vat_cents' => $gross - $net, 'vat_rate' => $rate,
                'vat_reason' => $rate === 19 ? null : $data['vat_reason'],
                'amount_words' => ucfirst($words).' und '.str_pad((string) ($gross % 100), 2, '0', STR_PAD_LEFT).'/100 '.$data['currency'],
                'signature_method' => $data['signature_method'],
                'club' => $club, 'created_by_name' => $actor->name, 'created_at' => now()->format('d.m.Y H:i:s T'), 'ip' => $ip,
                'notes' => $notes,
            ];
            $original = $this->pdf($snapshot, 'original', $signature, $settings->logoDataUri());
            $copy = $this->pdf($snapshot, 'copy', $signature, $settings->logoDataUri());

            return Receipt::query()->create([
                ...Arr::only($snapshot, ['receipt_number', 'receipt_date', 'amount_cents', 'currency', 'payer', 'payee', 'purpose']),
                'creation_key' => $data['creation_key'], 'snapshot' => $snapshot,
                'created_by' => $actor->id, 'created_by_name' => $actor->name,
                'encrypted_original' => Crypt::encryptString(base64_encode($original)), 'original_sha256' => hash('sha256', $original),
                'encrypted_copy' => Crypt::encryptString(base64_encode($copy)), 'copy_sha256' => hash('sha256', $copy),
            ]);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $snapshot */
    private function pdf(array $snapshot, string $edition, ?string $signature, ?string $logo): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('isPdfAEnabled', true);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A5', 'landscape');
        $pdf->loadHtml(view('receipts.document', [
            'receipt' => $snapshot, 'edition' => $edition,
            'signature' => $signature ? 'data:image/png;base64,'.base64_encode($signature) : null,
            'logo' => $logo,
        ])->render());
        $pdf->render();
        $output = $pdf->output();
        unset($pdf);
        gc_collect_cycles();

        return $output;
    }
}
