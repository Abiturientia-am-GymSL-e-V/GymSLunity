<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Security\SecurityAudit;
use App\SelfService\Access;
use App\SelfService\MemberPasskeys;
use App\Support\FormOfAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PasskeyController extends Controller
{
    public function __construct(private readonly MemberPasskeys $passkeys, private readonly SecurityAudit $audit) {}

    public function registrationOptions(Request $request): JsonResponse
    {
        return response()->json(['options' => $this->passkeys->registrationOptions($request, Access::member($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = Access::member($request);
        $values = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'credential' => ['required', 'array'],
        ]);
        $passkey = $this->passkeys->register($request, $member, trim($values['name']), $values['credential']);
        $this->record($request, 'selfservice_passkey_registered', $member, $passkey->id);

        return response()->json(['id' => (string) $passkey->id, 'name' => $passkey->name]);
    }

    public function destroy(Request $request, int $memberPasskey): RedirectResponse
    {
        $member = Access::member($request);
        $deleted = $member->passkeys()->whereKey($memberPasskey)->delete();
        abort_if($deleted === 0, 404);
        $this->record($request, 'selfservice_passkey_deleted', $member, $memberPasskey);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Der Passkey wurde entfernt.']);

        return back();
    }

    public function loginOptions(Request $request): JsonResponse
    {
        return response()->json(['options' => $this->passkeys->verificationOptions($request)]);
    }

    public function login(Request $request): JsonResponse
    {
        $values = $request->validate(['credential' => ['required', 'array']]);
        try {
            $passkey = $this->passkeys->verify($request, $values['credential']);
        } catch (ValidationException $exception) {
            $this->audit->record('selfservice_passkey_login', 'failed', $request);

            throw $exception;
        }
        $member = $passkey->member;
        Access::signIn($request, strtolower(trim((string) $member->email)), $member->id);
        $this->record($request, 'selfservice_passkey_login', $member, $passkey->id);
        Inertia::clearHistory();
        Inertia::flash('toast', ['type' => 'success', 'message' => FormOfAddress::choose('Du bist angemeldet.', 'Sie sind angemeldet.')]);

        return response()->json(['redirect' => '/selfservice']);
    }

    private function record(Request $request, string $event, Member $member, int $passkeyId): void
    {
        $this->audit->record($event, 'success', $request, context: ['passkey_id' => $passkeyId], subjectType: 'Member', subjectId: $member->member_number);
    }
}
