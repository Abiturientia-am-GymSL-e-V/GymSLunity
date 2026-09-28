<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\Iban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IbanRuleTest extends TestCase
{
    #[DataProvider('values')]
    public function test_it_validates_iban_values(mixed $value, bool $valid): void
    {
        $failed = false;
        (new Iban)->validate('iban', $value, function () use (&$failed): void {
            $failed = true;
        });

        $this->assertSame(! $valid, $failed);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function values(): iterable
    {
        yield 'valid german iban' => ['DE89370400440532013000', true];
        yield 'wrong checksum' => ['DE89370400440532013001', false];
        yield 'integer input fails without type error' => [12345678901234567, false];
        yield 'null fails without type error' => [null, false];
        yield 'array fails without type error' => [['DE89370400440532013000'], false];
    }
}
