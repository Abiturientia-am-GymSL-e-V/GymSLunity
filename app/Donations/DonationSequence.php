<?php

declare(strict_types=1);

namespace App\Donations;

use App\Support\DocumentSequence;

final class DonationSequence
{
    public static function next(string $kind, int $year): int
    {
        return DocumentSequence::next('donation_sequences', ['kind' => $kind, 'year' => $year]);
    }
}
