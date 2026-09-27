<?php

namespace Tests\Unit\Members;

use App\Members\MemberReportValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MemberReportValueTest extends TestCase
{
    #[DataProvider('emptyValues')]
    public function test_empty_report_values_remain_empty(mixed $value): void
    {
        $this->assertSame('', MemberReportValue::format($value, ['emptyLabel' => 'Keine']));
    }

    /** @return iterable<string, array{mixed}> */
    public static function emptyValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
    }

    public function test_meaningful_falsey_values_are_preserved(): void
    {
        $this->assertSame('Nein', MemberReportValue::format(false));
        $this->assertSame('0', MemberReportValue::format(0));
    }

    public function test_iban_is_formatted_in_groups_of_four(): void
    {
        $this->assertSame(
            'DE89 3704 0044 0532 0130 00',
            MemberReportValue::format('de89370400440532013000', ['key' => 'iban']),
        );
    }
}
