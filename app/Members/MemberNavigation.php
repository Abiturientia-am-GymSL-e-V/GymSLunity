<?php

declare(strict_types=1);

namespace App\Members;

use App\Http\Requests\Members\IndexMembersRequest;
use Illuminate\Support\Facades\Validator;

final class MemberNavigation
{
    public static function returnUrl(mixed $value): string
    {
        $fallback = route('members.index', absolute: false);
        if (! is_string($value) || strlen($value) > 3000 || preg_match('/[\\\\\x00-\x20]/', $value)) {
            return $fallback;
        }
        $parts = parse_url($value);
        if ($parts === false || ($parts['path'] ?? '') !== $fallback || isset($parts['host']) || isset($parts['scheme'])) {
            return $fallback;
        }
        parse_str($parts['query'] ?? '', $query);
        $validator = Validator::make($query, (new IndexMembersRequest)->rules());
        if ($validator->fails()) {
            return $fallback;
        }

        return route('members.index', $validator->validated(), absolute: false);
    }
}
