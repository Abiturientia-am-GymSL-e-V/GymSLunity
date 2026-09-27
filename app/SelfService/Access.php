<?php

namespace App\SelfService;

use App\Models\Member;
use App\Support\FormOfAddress;
use Illuminate\Http\Request;

final class Access
{
    public static function email(Request $request): string
    {
        abort_unless(is_string($request->session()->get('selfservice.email')) && (int) $request->session()->get('selfservice.until') > now()->getTimestamp(), 401, FormOfAddress::choose('Dein Zugang ist abgelaufen. Bitte fordere einen neuen E-Mail-Link an.', 'Ihr Zugang ist abgelaufen. Bitte fordern Sie einen neuen E-Mail-Link an.'));

        return $request->session()->get('selfservice.email');
    }

    public static function member(Request $request): Member
    {
        $email = self::email($request);
        $id = $request->session()->get('selfservice.member_id');
        abort_unless(is_int($id), 401);
        $member = Member::query()->whereKey($id)->firstOrFail();
        abort_unless(strtolower((string) $member->email) === $email && $member->deceased_at === null, 401);

        return $member;
    }

    public static function signIn(Request $request, string $email, ?int $id): void
    {
        $request->session()->regenerate(true);
        $request->session()->put('selfservice', ['email' => $email, 'member_id' => $id, 'until' => now()->getTimestamp() + 1800]);
    }
}
