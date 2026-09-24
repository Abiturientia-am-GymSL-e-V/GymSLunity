<?php

namespace Tests\Feature\Configuration;

use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClubLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_replace_and_remove_a_private_processed_logo(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->get(route('branding.logo'))->assertNotFound();
        $this->post(route('configuration.club.logo.store'), ['version' => 0, 'logo' => UploadedFile::fake()->image('logo.jpg', 120, 60)])->assertSessionHasNoErrors();
        $first = ClubSetting::current()->data['logo_path'];
        $this->assertStringStartsWith('branding/logo-', $first);
        $this->assertStringStartsWith("\x89PNG", Storage::disk('local')->get($first));
        $this->get(route('branding.logo'))->assertOk()->assertHeader('content-type', 'image/png');
        $this->patch(route('configuration.club.update'), ['version' => 1, 'name' => 'Testverein'])->assertSessionHasNoErrors();
        $this->assertSame($first, ClubSetting::current()->data['logo_path']);
        $this->post(route('configuration.club.logo.store'), ['version' => 2, 'logo' => UploadedFile::fake()->image('new.png', 80, 40)])->assertSessionHasNoErrors();
        $second = ClubSetting::current()->data['logo_path'];
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        $this->delete(route('configuration.club.logo.destroy'), ['version' => 3])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($second);
        $this->get(route('branding.logo'))->assertNotFound();
        $this->assertDatabaseCount('configuration_changes', 4);
    }

    public function test_logo_requires_admin_and_rejects_stale_version_or_unsupported_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['roles' => ['mv']]);
        $this->actingAs($user)->post(route('configuration.club.logo.store'), ['version' => 0, 'logo' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        $this->post(route('configuration.club.logo.store'), ['version' => 5, 'logo' => UploadedFile::fake()->image('logo.png')])->assertSessionHasErrors('version');
        $this->post(route('configuration.club.logo.store'), ['version' => 0, 'logo' => UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('logo');
        $this->assertSame([], Storage::disk('local')->files('branding'));
    }

    public function test_logo_data_uri_contains_the_stored_file_and_handles_missing_logos(): void
    {
        Storage::fake('local');
        $settings = ClubSetting::current();
        $this->assertNull($settings->logoDataUri());
        $path = 'branding/logo-12345678-1234-1234-1234-123456789abc.png';
        $contents = UploadedFile::fake()->image('logo.png')->getContent();
        Storage::disk('local')->put($path, $contents);
        $settings->data = [...$settings->data, 'logo_path' => $path];
        $this->assertSame('data:image/png;base64,'.base64_encode($contents), $settings->logoDataUri());
        Storage::disk('local')->delete($path);
        $this->assertNull($settings->logoDataUri());
    }

    public function test_logo_read_failure_after_path_resolution_returns_null(): void
    {
        Storage::fake('local');
        $settings = \Mockery::mock(ClubSetting::class)->makePartial();
        $settings->shouldReceive('logoPath')->once()->andReturn(Storage::disk('local')->path('removed-logo.png'));
        $this->assertNull($settings->logoDataUri());
    }
}
