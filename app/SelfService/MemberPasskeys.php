<?php

declare(strict_types=1);

namespace App\SelfService;

use App\Models\Member;
use App\Models\MemberPasskey;
use App\Support\FormOfAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn ceremonies for the member portal. The passkeys package expects an
 * authenticatable user, members sign in through the portal session instead,
 * so only its WebAuthn helpers are reused here.
 */
class MemberPasskeys
{
    public const LIMIT = 10;

    private const REGISTRATION = 'selfservice_passkey.registration';

    private const VERIFICATION = 'selfservice_passkey.verification';

    public function __construct(private readonly GenerateRegistrationOptions $registration) {}

    /** @return array<array-key, mixed> */
    public function registrationOptions(Request $request, Member $member): array
    {
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(name: Passkeys::relyingPartyName(), id: Passkeys::relyingPartyId()),
            user: PublicKeyCredentialUserEntity::create(
                name: strtolower((string) $member->email),
                id: self::userHandle($member),
                displayName: trim($member->first_name.' '.$member->last_name),
            ),
            challenge: random_bytes(32),
            pubKeyCredParams: $this->registration->supportedAlgorithms(),
            authenticatorSelection: $this->registration->authenticatorSelection(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $member->passkeys()->pluck('credential_id')->map(
                fn (string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    Base64UrlSafe::decodeNoPadding($id),
                ),
            )->all(),
            timeout: Passkeys::timeout(),
        );
        $request->session()->put(self::REGISTRATION, WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    /** @param array<string, mixed> $credential */
    public function register(Request $request, Member $member, string $name, array $credential): MemberPasskey
    {
        $serialized = $request->session()->pull(self::REGISTRATION);
        $response = $this->credential($credential)->response;
        if (! is_string($serialized) || ! $response instanceof AuthenticatorAttestationResponse) {
            throw self::failed();
        }
        $options = WebAuthn::fromJson($serialized, PublicKeyCredentialCreationOptions::class);
        // Options requested before switching to another member are useless.
        if (! hash_equals(self::userHandle($member), $options->user->id)) {
            throw self::failed();
        }
        if ($member->passkeys()->count() >= self::LIMIT) {
            throw ValidationException::withMessages(['credential' => 'Es sind höchstens '.self::LIMIT.' Passkeys möglich.']);
        }

        try {
            $source = WebAuthn::attestationValidator()->check(
                authenticatorAttestationResponse: $response,
                publicKeyCredentialCreationOptions: $options,
                host: Passkeys::relyingPartyId(),
            );
        } catch (Throwable) {
            throw self::failed();
        }

        $credentialId = Base64UrlSafe::encodeUnpadded($source->publicKeyCredentialId);
        if (MemberPasskey::query()->where('credential_id', $credentialId)->exists()) {
            throw self::failed();
        }

        return $member->passkeys()->create([
            'name' => $name,
            'credential_id' => $credentialId,
            'credential' => self::record($source),
        ]);
    }

    /** @return array<array-key, mixed> */
    public function verificationOptions(Request $request): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: Passkeys::relyingPartyId(),
            allowCredentials: [],
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: Passkeys::timeout(),
        );
        $request->session()->put(self::VERIFICATION, WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    /**
     * Returns the member of a valid passkey. Members who died or have no
     * e-mail address any more cannot use the portal, like with e-mail links.
     *
     * @param  array<string, mixed>  $credential
     */
    public function verify(Request $request, array $credential): MemberPasskey
    {
        $serialized = $request->session()->pull(self::VERIFICATION);
        $publicKey = $this->credential($credential);
        $response = $publicKey->response;
        if (! is_string($serialized) || ! $response instanceof AuthenticatorAssertionResponse) {
            throw self::failed();
        }
        $options = WebAuthn::fromJson($serialized, PublicKeyCredentialRequestOptions::class);

        return DB::transaction(function () use ($publicKey, $response, $options): MemberPasskey {
            $passkey = MemberPasskey::query()
                ->where('credential_id', Base64UrlSafe::encodeUnpadded($publicKey->rawId))
                ->lockForUpdate()
                ->first();
            $member = $passkey?->member;
            if ($passkey === null || $member === null || $member->deceased_at !== null || trim((string) $member->email) === '') {
                throw self::failed();
            }

            try {
                $record = WebAuthn::fromJson(json_encode($passkey->credential, JSON_THROW_ON_ERROR), CredentialRecord::class);
                $source = WebAuthn::assertionValidator()->check(
                    credentialRecord: $record,
                    authenticatorAssertionResponse: $response,
                    publicKeyCredentialRequestOptions: $options,
                    host: Passkeys::relyingPartyId(),
                    userHandle: $record->userHandle,
                );
            } catch (Throwable) {
                throw self::failed();
            }

            // The signature counter has to be stored to detect cloned keys.
            $passkey->forceFill(['credential' => self::record($source), 'last_used_at' => now()])->save();

            return $passkey;
        });
    }

    /** Stable, opaque WebAuthn user handle that does not reveal the member id. */
    public static function userHandle(Member $member): string
    {
        return hash_hmac('sha256', 'member:'.$member->id, (string) config('app.key'), true);
    }

    /** @param array<string, mixed> $credential */
    private function credential(array $credential): PublicKeyCredential
    {
        try {
            return WebAuthn::fromJson(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (Throwable) {
            throw self::failed();
        }
    }

    /** @return array<string, mixed> */
    private static function record(CredentialRecord $source): array
    {
        return json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function failed(): ValidationException
    {
        return ValidationException::withMessages(['credential' => FormOfAddress::choose(
            'Der Passkey konnte nicht geprüft werden. Bitte versuche es erneut oder fordere einen E-Mail-Link an.',
            'Der Passkey konnte nicht geprüft werden. Bitte versuchen Sie es erneut oder fordern Sie einen E-Mail-Link an.',
        )]);
    }
}
