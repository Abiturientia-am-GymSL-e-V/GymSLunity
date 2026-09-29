<?php

declare(strict_types=1);

namespace Tests\Unit\SelfService;

use App\SelfService\EmailAddressFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailAddressFilterTest extends TestCase
{
    public function test_parse_normalizes_case_whitespace_blank_lines_and_duplicates(): void
    {
        $this->assertSame(
            ['*@gymsl.de', 'm.mustermann@*'],
            EmailAddressFilter::parse("  *@GymSL.de \r\n\n\t\nm.mustermann@*\n*@gymsl.de\n"),
        );
    }

    /** @return array<string, array{string, string, bool}> */
    public static function patternCases(): array
    {
        return [
            'domain' => ['*@gymsl.de', 'ada@gymsl.de', true],
            'domain is case insensitive' => ['*@GYMSL.DE', 'Ada@GymSL.de', true],
            'surrounding whitespace' => ['*@gymsl.de', '  ada@gymsl.de ', true],
            'domain does not cover subdomain' => ['*@gymsl.de', 'ada@mail.gymsl.de', false],
            'subdomain' => ['*@*.gymsl.de', 'ada@mail.gymsl.de', true],
            'nested subdomain' => ['*@*.gymsl.de', 'ada@a.b.gymsl.de', true],
            'subdomain pattern excludes parent' => ['*@*.gymsl.de', 'ada@gymsl.de', false],
            'suffix trick' => ['*@*.gymsl.de', 'ada@evilgymsl.de', false],
            'local part' => ['m.mustermann@*', 'm.mustermann@example.org', true],
            'dot is literal' => ['m.mustermann@*', 'mxmustermann@example.org', false],
            'wildcard does not span at sign' => ['*@*', 'ada@gymsl.de', true],
            'several wildcards' => ['a*a@*.*.de', 'anna@x.y.de', true],
            'several wildcards mismatch' => ['a*a@*.*.de', 'anna@y.de', false],
            'exact address' => ['ada@gymsl.de', 'ada@gymsl.de', true],
            'no partial match' => ['ada@gymsl.de', 'xada@gymsl.de', false],
            'regex characters are literal' => ['a+b@gymsl.de', 'a+b@gymsl.de', true],
            'regex characters do not act as regex' => ['a+b@gymsl.de', 'aab@gymsl.de', false],
        ];
    }

    #[DataProvider('patternCases')]
    public function test_patterns_match_whole_addresses(string $pattern, string $email, bool $expected): void
    {
        $this->assertSame($expected, EmailAddressFilter::matchesAny(
            EmailAddressFilter::normalizeEmail($email),
            EmailAddressFilter::parse($pattern),
        ));
    }

    public function test_invalid_patterns_are_reported_per_line_and_never_match(): void
    {
        $lines = "*@gymsl.de\ngymsl.de\n\na@b@c\n*@ gymsl.de\n@gymsl.de\n*@gym..sl.de\n*@gym/sl.de";
        $errors = EmailAddressFilter::errors($lines);

        $this->assertSame([2, 4, 5, 6, 7, 8], array_keys($errors));
        $this->assertStringContainsString('Zeile 2', $errors[2]);
        $this->assertFalse(EmailAddressFilter::matchesAny('gymsl.de', ['gymsl.de']));
        $this->assertFalse(EmailAddressFilter::matchesAny('a@b@c', ['a@b@c']));
    }
}
