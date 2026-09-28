<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_store_view_and_remove_a_profile_signature(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('profile.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('hasProfileSignature', false));

        $this->post(route('profile.signature.store'), [
            'signature' => UploadedFile::fake()->image('unterschrift.png', 500, 150),
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertTrue($user->hasProfileSignature());
        $this->assertStringStartsWith("\x89PNG", $user->profileSignature());
        $this->assertStringNotContainsString("\x89PNG", DB::table('users')->where('id', $user->id)->value('encrypted_signature'));
        $this->get(route('profile.signature.show'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->delete(route('profile.signature.destroy'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));
        $this->assertFalse($user->refresh()->hasProfileSignature());
        $this->get(route('profile.signature.show'))->assertNotFound();
    }

    public function test_profile_signature_upload_only_accepts_bounded_images(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('profile.signature.store'), [
            'signature' => UploadedFile::fake()->create('unterschrift.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('signature');

        $this->post(route('profile.signature.store'), [
            'signature' => UploadedFile::fake()->image('unterschrift.png', 2500, 200),
        ])->assertSessionHasErrors('signature');

        $this->assertFalse($user->refresh()->hasProfileSignature());
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
