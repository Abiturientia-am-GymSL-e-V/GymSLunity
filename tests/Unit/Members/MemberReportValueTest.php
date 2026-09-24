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
}
