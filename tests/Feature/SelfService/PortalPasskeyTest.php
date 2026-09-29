<?php

declare(strict_types=1);

namespace Tests\Feature\SelfService;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberPasskey;
use App\SelfService\MemberPasskeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class PortalPasskeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'selfservice_enabled' => true, 'name' => 'Testverein']]);
    }

    private function signIn(Member $member): self
    {
        return $this->withSession(['selfservice' => ['email' => strtolower($member->email), 'member_id' => $member->id, 'until' => time() + 1800]]);
    }

    private function passkey(Member $member, string $name = 'Smartphone'): MemberPasskey
    {
        return $member->passkeys()->create(['name' => $name, 'credential_id' => bin2hex(random_bytes(8)), 'credential' => ['userHandle' => 'x']]);
    }

    /** @return array<string, mixed> */
    private function assertion(): array
    {
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $id = $encode(random_bytes(16));

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $encode(json_encode(['type' => 'webauthn.get', 'challenge' => $encode(random_bytes(32)), 'origin' => 'http://localhost'])),
                'authenticatorData' => $encode(hash('sha256', 'localhost', true).chr(5).pack('N', 1)),
                'signature' => $encode(random_bytes(64)),
            ],
        ];
    }

    public function test_registration_options_need_a_portal_session_and_use_an_opaque_user_handle(): void
    {
        $member = Member::factory()->create(['email' => 'mia@example.com']);
        $this->getJson('/selfservice/passkeys/optionen')->assertUnauthorized();

        $response = $this->signIn($member)->getJson('/selfservice/passkeys/optionen')->assertOk();

        $handle = rtrim(strtr(base64_encode(MemberPasskeys::userHandle($member)), '+/', '-_'), '=');
        $this->assertSame($handle, $response->json('options.user.id'));
        $this->assertSame('mia@example.com', $response->json('options.user.name'));
        $this->assertStringNotContainsString((string) $member->id, (string) $response->json('options.user.id'));
        $this->assertNotNull(session('selfservice_passkey.registration'));
    }

    public function test_a_passkey_cannot_be_stored_without_a_registration_ceremony(): void
    {
        $member = Member::factory()->create();

        $this->signIn($member)->postJson('/selfservice/passkeys', ['name' => 'Laptop', 'credential' => $this->assertion()])
            ->assertJsonValidationErrors('credential');
        $this->assertSame(0, MemberPasskey::query()->count());
    }

    public function test_the_portal_lists_and_removes_only_own_passkeys(): void
    {
        $member = Member::factory()->create();
        $other = Member::factory()->create();
        $own = $this->passkey($member, 'Mein Telefon');
        $foreign = $this->passkey($other);

        $this->signIn($member)->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->has('passkeys', 1)
            ->where('passkeys.0.name', 'Mein Telefon')
            ->missing('passkeys.0.credential'));

        $this->signIn($member)->delete("/selfservice/passkeys/{$foreign->id}")->assertNotFound();
        $this->signIn($member)->delete("/selfservice/passkeys/{$own->id}")->assertRedirect();

        $this->assertModelMissing($own);
        $this->assertModelExists($foreign);
        $this->assertDatabaseHas('security_audit_events', ['event' => 'selfservice_passkey_deleted', 'subject_id' => (string) $member->member_number]);
    }

    public function test_an_unknown_passkey_does_not_sign_in(): void
    {
        $this->getJson('/selfservice/passkey-anmeldung/optionen')->assertOk()->assertJsonPath('options.allowCredentials', []);

        $this->postJson('/selfservice/passkey-anmeldung', ['credential' => $this->assertion()])
            ->assertJsonValidationErrors('credential');

        $this->assertNull(session('selfservice'));
        $this->assertDatabaseHas('security_audit_events', ['event' => 'selfservice_passkey_login', 'outcome' => 'failed']);
    }

    public function test_a_login_without_requested_options_is_rejected(): void
    {
        $this->postJson('/selfservice/passkey-anmeldung', ['credential' => $this->assertion()])
            ->assertJsonValidationErrors('credential');
    }

    public function test_a_passkey_of_a_deceased_member_is_rejected_before_the_signature_check(): void
    {
        $member = Member::factory()->create(['deceased_at' => '2026-01-01']);
        $assertion = $this->assertion();
        $member->passkeys()->create(['name' => 'Alt', 'credential_id' => $assertion['rawId'], 'credential' => ['userHandle' => 'x']]);

        $this->getJson('/selfservice/passkey-anmeldung/optionen')->assertOk();
        $this->postJson('/selfservice/passkey-anmeldung', ['credential' => $assertion])
            ->assertJsonValidationErrors('credential');

        $this->assertNull(session('selfservice'));
    }

    public function test_a_verified_passkey_opens_the_portal_session(): void
    {
        $member = Member::factory()->create(['email' => 'Paul@Example.com']);
        $passkey = $this->passkey($member);
        $this->mock(MemberPasskeys::class, fn ($mock) => $mock->shouldReceive('verify')
            ->once()
            ->with(Mockery::type(Request::class), Mockery::type('array'))
            ->andReturn($passkey));

        $this->postJson('/selfservice/passkey-anmeldung', ['credential' => $this->assertion()])
            ->assertOk()
            ->assertJson(['redirect' => '/selfservice']);

        $this->assertSame('paul@example.com', session('selfservice.email'));
        $this->assertSame($member->id, session('selfservice.member_id'));
        $this->assertSame(1, DB::table('security_audit_events')->where('event', 'selfservice_passkey_login')->where('outcome', 'success')->count());
        $this->get('/selfservice')->assertOk();
    }
}
