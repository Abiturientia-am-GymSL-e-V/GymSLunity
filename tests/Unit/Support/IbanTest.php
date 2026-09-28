<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Iban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IbanTest extends TestCase
{
    #[DataProvider('values')]
    public function test_it_formats_iban_values_in_groups_of_four(?string $value, string $expected): void
    {
        $this->assertSame($expected, Iban::format($value));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function values(): iterable
    {
        yield 'compact' => ['DE89370400440532013000', 'DE89 3704 0044 0532 0130 00'];
        yield 'existing whitespace' => ["de89 3704\t0044 0532 0130 00", 'DE89 3704 0044 0532 0130 00'];
        yield 'empty' => [null, ''];
    }
}
