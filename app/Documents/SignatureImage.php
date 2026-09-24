<?php

namespace App\Documents;

use GdImage;
use InvalidArgumentException;

final class SignatureImage
{
    public const MAX_BYTES = 2_097_152;

    public const MAX_DATA_URL_LENGTH = 2_800_000;

    public static function fromDataUrl(string $value): string
    {
        if (strlen($value) > self::MAX_DATA_URL_LENGTH
            || ! preg_match('/\Adata:image\/png;base64,([A-Za-z0-9+\/=]+)\z/', $value, $matches)) {
            throw new InvalidArgumentException('Die gezeichnete Unterschrift ist ungültig.');
        }

        $binary = base64_decode($matches[1], true);
        if (! is_string($binary)) {
            throw new InvalidArgumentException('Die gezeichnete Unterschrift ist ungültig.');
        }

        return self::normalize($binary);
    }

    public static function normalize(string $binary): string
    {
        if ($binary === '' || strlen($binary) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Die Unterschrift darf höchstens 2 MB groß sein.');
        }

        $info = @getimagesizefromstring($binary);
        if (! is_array($info)
            || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new InvalidArgumentException('Bitte eine PNG-, JPEG- oder WebP-Bilddatei verwenden.');
        }

        [$width, $height] = $info;
        if ($width < 40 || $height < 20 || $width > 2400 || $height > 1200) {
            throw new InvalidArgumentException('Die Unterschrift muss zwischen 40 × 20 und 2400 × 1200 Pixel groß sein.');
        }

        $image = @imagecreatefromstring($binary);
        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('Die Bilddatei der Unterschrift konnte nicht gelesen werden.');
        }

        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            if (! imagepng($image, null, 6)) {
                ob_end_clean();
                throw new InvalidArgumentException('Die Bilddatei der Unterschrift konnte nicht verarbeitet werden.');
            }
            $png = ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if (! is_string($png) || $png === '') {
            throw new InvalidArgumentException('Die Bilddatei der Unterschrift konnte nicht verarbeitet werden.');
        }

        return $png;
    }
}
