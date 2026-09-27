<?php

namespace App\Payments;

use App\Support\Iban as IbanFormatter;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class GiroCode
{
    /** @return array{amount: string, recipient: string, iban: string, bic: string, purpose: string, image: string} */
    public function create(int $amountCents, string $recipient, string $iban, ?string $bic, int|string $purpose): array
    {
        $amount = number_format($amountCents / 100, 2, '.', '');
        $recipient = $this->line($recipient, 70);
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        $bic = strtoupper(preg_replace('/\s+/', '', (string) $bic) ?? '');
        $purpose = $this->line(is_int($purpose) ? 'Mitgliedsbeitrag Mitglied '.$purpose : $purpose, 140);
        $payload = implode("\n", [
            'BCD', '002', '1', 'SCT', $bic, $recipient, $iban,
            'EUR'.$amount, '', '', $purpose, '',
        ]);
        $renderer = new ImageRenderer(new RendererStyle(360, 3), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($payload, 'UTF-8');

        return [
            'amount' => $amount,
            'recipient' => $recipient,
            'iban' => IbanFormatter::format($iban),
            'bic' => $bic,
            'purpose' => $purpose,
            'image' => 'data:image/svg+xml;base64,'.base64_encode($svg),
        ];
    }

    private function line(string $value, int $length): string
    {
        return mb_substr(trim((string) preg_replace('/[\r\n]+/', ' ', $value)), 0, $length);
    }
}
