<?php

declare(strict_types=1);

namespace Tests\Unit\Banking;

use App\Banking\BankCsvReader;
use PHPUnit\Framework\TestCase;

class BankCsvReaderTest extends TestCase
{
    public function test_amounts_are_parsed_without_floats_in_german_and_international_notation(): void
    {
        $this->assertSame(123456, BankCsvReader::amount('1.234,56'));
        $this->assertSame(123456, BankCsvReader::amount('1,234.56'));
        $this->assertSame(-1250, BankCsvReader::amount('-12,50'));
        $this->assertSame(-1250, BankCsvReader::amount('12,50-'));
        $this->assertSame(1250, BankCsvReader::amount('12.5'));
        $this->assertSame(150000, BankCsvReader::amount('1.500'));
        $this->assertSame(150000, BankCsvReader::amount('1.500,00 €'));
        $this->assertSame(1999, BankCsvReader::amount('19,99'));
        $this->assertSame(0, BankCsvReader::amount('abc'));
        $this->assertSame(0, BankCsvReader::amount('1,2,3'));
    }

    public function test_references_match_only_as_whole_words(): void
    {
        $this->assertTrue(BankCsvReader::containsToken('Zahlung RW-2026-000001 danke', 'RW-2026-000001'));
        $this->assertTrue(BankCsvReader::containsToken('rw-2026-000001', 'RW-2026-000001'));
        $this->assertFalse(BankCsvReader::containsToken('Zahlung RW-2026-0000012', 'RW-2026-000001'));
        $this->assertFalse(BankCsvReader::containsToken('XRW-2026-000001', 'RW-2026-000001'));
        $this->assertFalse(BankCsvReader::containsToken('irgendwas', ''));
    }

    public function test_ambiguous_matches_are_not_assigned(): void
    {
        $candidates = [['id' => 1, 'ref' => 'ABC-1'], ['id' => 2, 'ref' => 'ABC-2']];
        $tokens = fn (array $candidate): array => [$candidate['ref']];

        $this->assertSame(2, BankCsvReader::uniqueMatch('Zahlung ABC-2', $candidates, $tokens)['id'] ?? null);
        $this->assertNull(BankCsvReader::uniqueMatch('ABC-1 und ABC-2', $candidates, $tokens));
        $this->assertNull(BankCsvReader::uniqueMatch('nichts', $candidates, $tokens));
    }
}
