<?php

declare(strict_types=1);

namespace App\Forms;

use App\Documents\SignatureImage;
use App\Members\MemberReportWriter;
use App\Models\ClubSetting;
use App\Models\FinanceMandate;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class FinanceMandates
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): FinanceMandate
    {
        return DB::transaction(function () use ($data, $actor): FinanceMandate {
            $settings = ClubSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->can('view-forms'), 403);
            $existing = FinanceMandate::query()->where('creation_key', $data['creation_key'])->first();
            if ($existing) {
                abort_unless($existing->created_by === $actor->id, 403);

                return $existing;
            }
            $club = $settings->data;
            foreach (['name', 'street', 'postal_code', 'city', 'country', 'creditor_id'] as $key) {
                if (empty($club[$key])) {
                    throw ValidationException::withMessages(['club' => 'Für SEPA-Mandate müssen Vereinsname, Anschrift, Land und Gläubiger-ID in der Vereinskonfiguration hinterlegt sein.']);
                }
            }
            $year = (int) now()->format('Y');
            $next = (int) (DB::table('finance_mandate_sequences')->where('year', $year)->value('next_number') ?? 1);
            do {
                $reference = 'RM-'.$year.'-'.str_pad((string) $next++, 6, '0', STR_PAD_LEFT);
            } while (FinanceMandate::query()->where('mandate_reference', $reference)->exists());
            DB::table('finance_mandate_sequences')->updateOrInsert(['year' => $year], ['next_number' => $next]);
            $token = Str::random(64);
            $text = FinanceMandateText::render((string) ($club['finance_mandate_text'] ?? FinanceMandateText::DEFAULT), $club);
            $attributes = [
                ...Arr::only($data, ['creation_key', 'debtor_name', 'debtor_street', 'debtor_postal_code', 'debtor_city', 'debtor_country', 'debtor_email', 'iban', 'mandate_type']),
                'debtor_email' => $data['debtor_email'] ?? null,
                'mandate_reference' => $reference,
                'status' => 'pending',
                'mandate_text' => $text,
                'signing_token_hash' => hash('sha256', $token),
                'encrypted_signing_token' => Crypt::encryptString($token),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ];
            $pdf = $this->renderPdf($attributes, $club, null);

            return FinanceMandate::query()->create([
                ...$attributes,
                'encrypted_pdf' => Crypt::encryptString(base64_encode($pdf)),
                'pdf_sha256' => hash('sha256', $pdf),
            ]);
        }, attempts: 3);
    }

    public function markPaperSigned(FinanceMandate $mandate, User $actor, string $signedByName, string $signedAt): FinanceMandate
    {
        return $this->sign($mandate, $actor, $signedByName, $signedAt, 'paper', null);
    }

    public function signDigitally(FinanceMandate $mandate, string $signedByName, string $signatureData): FinanceMandate
    {
        try {
            $signature = SignatureImage::fromDataUrl($signatureData);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['signature_data' => $exception->getMessage()]);
        }

        return $this->sign($mandate, null, $signedByName, now()->toDateString(), 'digital', $signature);
    }

    public function revoke(FinanceMandate $mandate, User $actor, string $reason): FinanceMandate
    {
        return DB::transaction(function () use ($mandate, $actor, $reason): FinanceMandate {
            $current = FinanceMandate::query()->lockForUpdate()->findOrFail($mandate->id);
            if ($current->status === 'revoked') {
                return $current;
            }
            abort_unless($actor->fresh()?->can('view-forms'), 403);
            $current->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoked_by_name' => $actor->name,
                'revocation_reason' => trim($reason),
            ]);

            return $current->fresh();
        }, attempts: 3);
    }

    private function sign(FinanceMandate $mandate, ?User $actor, string $signedByName, string $signedAt, string $method, ?string $signature): FinanceMandate
    {
        return DB::transaction(function () use ($mandate, $actor, $signedByName, $signedAt, $method, $signature): FinanceMandate {
            $current = FinanceMandate::query()->lockForUpdate()->findOrFail($mandate->id);
            if ($current->status === 'signed') {
                return $current;
            }
            if ($current->status === 'revoked') {
                throw ValidationException::withMessages(['mandate' => 'Ein widerrufenes Mandat kann nicht mehr unterzeichnet werden.']);
            }
            $club = ClubSetting::current()->data;
            $values = [
                'status' => 'signed',
                'signed_at' => $signedAt.' '.now()->format('H:i:s'),
                'signature_method' => $method,
                'signed_by_name' => $signedByName,
                'signed_by' => $actor?->id,
                'encrypted_signature' => $signature ? Crypt::encryptString(base64_encode($signature)) : null,
            ];
            $pdf = $this->renderPdf([...$current->attributesToArray(), ...$values], $club, $signature);
            $current->update([
                ...$values,
                'encrypted_pdf' => Crypt::encryptString(base64_encode($pdf)),
                'pdf_sha256' => hash('sha256', $pdf),
            ]);

            return $current->fresh();
        }, attempts: 3);
    }

    /** @param array<string, mixed> $mandate
     * @param  array<string, mixed>  $club
     */
    private function renderPdf(array $mandate, array $club, ?string $signature): string
    {
        $settings = ClubSetting::current();
        $html = view('forms.sepa-mandate', [
            'mandate' => $mandate,
            'club' => $club,
            'logo' => $settings->logoDataUri(),
            'signature' => $signature ? 'data:image/png;base64,'.base64_encode($signature) : null,
        ])->render();

        return MemberReportWriter::pdf($html);
    }
}
