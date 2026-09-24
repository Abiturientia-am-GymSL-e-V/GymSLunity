<?php

namespace App\Security;

final class SafeCsv
{
    public static function value(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s\x00-\x1F]*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value;
    }
}
