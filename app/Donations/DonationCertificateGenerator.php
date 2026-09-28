<?php

declare(strict_types=1);

namespace App\Donations;

use App\Configuration\ClubSettings;
use App\Members\MemberReportWriter;
use App\Models\Donation;
use App\Models\DonationCertificate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DonationCertificateGenerator
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    /**
     * @param  array<string, mixed>  $club
     * @return list<string>
     */
    public static function configurationErrors(array $club): array
    {
        $required = [
            'name' => 'Vereinsname',
            'street' => 'Straße des Vereins',
            'postal_code' => 'Postleitzahl des Vereins',
            'city' => 'Ort des Vereins',
            'tax_office' => 'Finanzamt',
            'tax_number' => 'Steuernummer',
            'tax_privilege_notice_type' => 'Art des Steuerbescheids',
            'tax_privilege_notice_date' => 'Datum des Steuerbescheids',
        ];
        $errors = [];
        foreach ($required as $key => $label) {
            if (! is_string($club[$key] ?? null) || trim($club[$key]) === '') {
                $errors[] = $label.' fehlt.';
            }
        }
        $type = $club['tax_privilege_notice_type'] ?? null;
        if (in_array($type, ['exemption_notice', 'corporate_tax_attachment'], true)
            && (! is_string($club['tax_privilege_assessment_period'] ?? null) || trim($club['tax_privilege_assessment_period']) === '')) {
            $errors[] = 'Letzter Veranlagungszeitraum fehlt.';
        }
        $purposeCodes = $club['donation_purpose_codes'] ?? [];
        if (! is_array($purposeCodes) || count($purposeCodes) === 0) {
            $errors[] = 'Mindestens ein steuerbegünstigter Zweck fehlt.';
        }
        if (is_string($club['tax_privilege_notice_date'] ?? null)) {
            try {
                $noticeDate = CarbonImmutable::parse($club['tax_privilege_notice_date'])->startOfDay();
                $validYears = $type === 'section_60a_notice' ? 3 : 5;
                if (now()->startOfDay()->greaterThan($noticeDate->addYears($validYears))) {
                    $errors[] = 'Der hinterlegte Steuerbescheid liegt außerhalb der gesetzlichen Gültigkeitsfrist.';
                }
            } catch (\Throwable) {
                $errors[] = 'Das Datum des Steuerbescheids ist ungültig.';
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string, mixed> $club */
    public static function digitalDeliveryAllowed(array $club): bool
    {
        return (bool) ($club['certificate_machine_generated_notified'] ?? false);
    }

    public function issue(Donation $donation, User $actor, string $signatureMethod = 'digital', ?string $signatureImage = null): DonationCertificate
    {
        if (! in_array($signatureMethod, ['digital', 'profile', 'drawn', 'print'], true)
            || (in_array($signatureMethod, ['profile', 'drawn'], true) && ! is_string($signatureImage))) {
            throw ValidationException::withMessages(['signature_method' => 'Bitte eine gültige Unterschriftsart wählen.']);
        }

        return DB::transaction(function () use ($donation, $actor, $signatureMethod, $signatureImage): DonationCertificate {
            $locked = Donation::query()->whereKey($donation->getKey())->lockForUpdate()->firstOrFail();
            $existing = DonationCertificate::query()->where('donation_id', $locked->id)->first();
            if ($existing) {
                return $existing;
            }

            $settings = $this->clubSettings;
            $club = $settings->data();
            $errors = self::configurationErrors($club);
            if ($errors !== []) {
                throw ValidationException::withMessages(['certificate' => $errors]);
            }
            if ($locked->donation_type === 'membership_fee' && ! (bool) ($club['contributions_tax_deductible'] ?? false)) {
                throw ValidationException::withMessages(['certificate' => 'Mitgliedsbeiträge sind laut Konfiguration nicht steuerlich abzugsfähig.']);
            }
            $purposeCodes = is_array($club['donation_purpose_codes'] ?? null) ? $club['donation_purpose_codes'] : [];
            if (! in_array($locked->purpose_code, $purposeCodes, true)) {
                throw ValidationException::withMessages(['certificate' => 'Der Zweck der Spende ist im aktuellen Steuerbescheid nicht freigegeben.']);
            }
            if (! self::digitalDeliveryAllowed($club) && $signatureMethod !== 'print') {
                throw ValidationException::withMessages([
                    'signature_method' => 'Ohne Anzeige des maschinellen Verfahrens beim Finanzamt darf die Bestätigung nur zum Ausdrucken und eigenhändigen Unterzeichnen erstellt werden.',
                ]);
            }

            $signedAt = now();
            $year = (int) $signedAt->format('Y');
            $certificateNumber = sprintf('ZB-%d-%06d', $year, DonationSequence::next('certificate', $year));
            $snapshot = $this->snapshot(
                $locked,
                $club,
                $actor,
                $certificateNumber,
                $signedAt->toISOString(),
                $signatureMethod,
                $signatureImage,
            );
            $snapshot['digital_signature'] = hash_hmac(
                'sha256',
                json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (string) config('app.key'),
            );
            $pdf = $this->render($snapshot, $settings->logoDataUri(), $signatureImage);
            $certificate = DonationCertificate::query()->create([
                'donation_id' => $locked->id,
                'certificate_number' => $certificateNumber,
                'encrypted_pdf' => Crypt::encryptString(base64_encode($pdf)),
                'pdf_sha256' => hash('sha256', $pdf),
                'snapshot' => $snapshot,
                'encrypted_signature_image' => is_string($signatureImage) ? Crypt::encryptString($signatureImage) : null,
                'signed_by' => $actor->id,
                'signed_by_name' => $actor->name,
                'signed_at' => $signedAt,
                'created_at' => $signedAt,
            ]);
            DonationAudit::record($actor, 'certificate_issued', [
                'certificate_number' => $certificateNumber,
                'pdf_sha256' => $certificate->pdf_sha256,
                'digital_signature' => $snapshot['digital_signature'],
                'signature_method' => $signatureMethod,
                'signature_image_sha256' => $snapshot['signature_image_sha256'],
            ], $locked);

            return $certificate;
        });
    }

    public function revokedPdf(DonationCertificate $certificate, string $reason, string $revokedAt): string
    {
        return $this->render(
            $certificate->snapshot,
            $this->clubSettings->logoDataUri(),
            $certificate->signatureImage(),
            ['reason' => $reason, 'revoked_at' => $revokedAt],
        );
    }

    /**
     * @param  array<string, mixed>  $club
     * @return array<string, mixed>
     */
    private function snapshot(
        Donation $donation,
        array $club,
        User $actor,
        string $certificateNumber,
        string $signedAt,
        string $signatureMethod,
        ?string $signatureImage,
    ): array {
        return [
            'certificate_number' => $certificateNumber,
            'donation' => [
                'receipt_number' => $donation->receipt_number,
                'donor_name' => $donation->donor_name,
                'donor_street' => $donation->donor_street,
                'donor_postal_code' => $donation->donor_postal_code,
                'donor_city' => $donation->donor_city,
                'donor_country' => $donation->donor_country,
                'donation_type' => $donation->donation_type,
                'amount_cents' => $donation->amount_cents,
                'donated_at' => $donation->donated_at->format('Y-m-d'),
                'purpose_code' => $donation->purpose_code,
                'purpose_label' => $donation->purpose_label,
                'description' => $donation->description,
                'asset_origin' => $donation->asset_origin,
                'valuation_document_reference' => $donation->valuation_document_reference,
                'expense_waiver' => $donation->expense_waiver,
            ],
            'club' => [
                'name' => $club['name'],
                'street' => $club['street'],
                'postal_code' => $club['postal_code'],
                'city' => $club['city'],
                'register_number' => $club['register_number'] ?? null,
                'register_court' => $club['register_court'] ?? null,
                'tax_number' => $club['tax_number'],
                'tax_office' => $club['tax_office'],
                'notice_type' => $club['tax_privilege_notice_type'],
                'notice_date' => $club['tax_privilege_notice_date'],
                'assessment_period' => $club['tax_privilege_assessment_period'] ?? null,
                'contributions_tax_deductible' => (bool) ($club['contributions_tax_deductible'] ?? false),
                'certificate_location' => $club['city'],
                'machine_generated_notified' => self::digitalDeliveryAllowed($club),
            ],
            'signed_by' => $actor->name,
            'signed_at' => $signedAt,
            'signature_method' => $signatureMethod,
            'signature_image_sha256' => is_string($signatureImage) ? hash('sha256', $signatureImage) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{reason: string, revoked_at: string}|null  $revocation
     */
    private function render(array $snapshot, ?string $logo, ?string $signatureImage, ?array $revocation = null): string
    {
        $donation = $snapshot['donation'];
        $club = $snapshot['club'];
        $html = view('donations.certificate', [
            'snapshot' => $snapshot,
            'amountWords' => $this->amountWords((int) $donation['amount_cents']),
            'taxStatement' => $this->taxStatement([
                'tax_privilege_notice_type' => $club['notice_type'],
                'tax_office' => $club['tax_office'],
                'tax_number' => $club['tax_number'],
                'tax_privilege_notice_date' => $club['notice_date'],
                'tax_privilege_assessment_period' => $club['assessment_period'],
            ], (string) $donation['purpose_label']),
            'signatureImage' => is_string($signatureImage) ? 'data:image/png;base64,'.base64_encode($signatureImage) : null,
            'logo' => $logo,
            'revocation' => $revocation,
        ])->render();

        return MemberReportWriter::pdf($html);
    }

    /** @param array<string, mixed> $club */
    private function taxStatement(array $club, string $purpose): string
    {
        if ($club['tax_privilege_notice_type'] === 'section_60a_notice') {
            return sprintf(
                'Die Einhaltung der satzungsmäßigen Voraussetzungen nach den §§ 51, 59, 60 und 61 AO wurde vom Finanzamt %s, StNr. %s, mit Bescheid vom %s nach § 60a AO gesondert festgestellt. Wir fördern nach unserer Satzung %s.',
                $club['tax_office'], $club['tax_number'], $this->date($club['tax_privilege_notice_date']), $this->purposeObject($purpose),
            );
        }

        $notice = $club['tax_privilege_notice_type'] === 'corporate_tax_attachment'
            ? 'der Anlage zum Körperschaftsteuerbescheid'
            : 'dem Freistellungsbescheid';

        return sprintf(
            'Wir sind wegen %s nach %s des Finanzamtes %s, StNr. %s, vom %s für den letzten Veranlagungszeitraum %s nach § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes von der Körperschaftsteuer und nach § 3 Nr. 6 des Gewerbesteuergesetzes von der Gewerbesteuer befreit.',
            $purpose, $notice, $club['tax_office'], $club['tax_number'], $this->date($club['tax_privilege_notice_date']), $club['tax_privilege_assessment_period'],
        );
    }

    private function amountWords(int $amountCents): string
    {
        $names = ['null', 'eins', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun'];
        $euros = (string) intdiv($amountCents, 100);
        $cents = str_pad((string) ($amountCents % 100), 2, '0', STR_PAD_LEFT);
        $spell = fn (string $value): string => implode(' – ', array_map(fn (string $digit): string => $names[(int) $digit], str_split($value)));

        return 'X '.$spell($euros).' Euro und '.$spell($cents).' Cent X';
    }

    private function date(string $value): string
    {
        return CarbonImmutable::parse($value)->format('d.m.Y');
    }

    private function purposeObject(string $purpose): string
    {
        return str_starts_with($purpose, 'Förderung ')
            ? lcfirst(substr($purpose, strlen('Förderung ')))
            : $purpose;
    }
}
