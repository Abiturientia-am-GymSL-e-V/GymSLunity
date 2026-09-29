<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Backup\ApplicationBackup;
use App\Backup\BackupSignature;
use App\Backup\ConfigurationBackup;
use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BackupConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_download_complete_configuration_backup(): void
    {
        ClubSetting::current()->update(['data' => ['name' => 'Turnverein', 'selfservice_enabled' => true]]);
        $admin = User::factory()->create(['roles' => ['admin']]);

        $response = $this->actingAs($admin)->get(route('configuration.backup.configuration.download'));

        $response->assertOk()->assertDownload();
        $document = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('gymslunity-configuration-backup', $document['format']);
        $this->assertSame(2, $document['format_version']);
        $this->assertSame(1, $document['payload']['club_settings']['id']);
        $this->assertNotEmpty($document['payload']['member_field_definitions']);
        $this->assertSame(1, $document['payload']['mail_settings']['id']);
        $this->assertArrayNotHasKey('users', $document['payload']);
        $this->assertArrayNotHasKey('members', $document['payload']);
    }

    public function test_administrator_can_restore_configuration_backup(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        Storage::fake('local');
        $logoPath = 'branding/logo-00000000-0000-0000-0000-000000000000.png';
        $logo = "\x89PNG\r\n\x1a\nlogo";
        Storage::disk('local')->put($logoPath, $logo);
        ClubSetting::current()->update(['data' => ['name' => 'Gesicherter Verein', 'logo_path' => $logoPath]]);
        $backup = app(ConfigurationBackup::class)->export();
        $fieldCount = DB::table('member_field_definitions')->count();

        ClubSetting::current()->update(['data' => ['name' => 'Geänderter Verein']]);
        Storage::disk('local')->delete($logoPath);
        DB::table('member_field_definitions')->where('id', DB::table('member_field_definitions')->min('id'))->delete();

        $response = $this->actingAs($admin)->post(route('configuration.backup.configuration.restore'), [
            'configuration_backup' => UploadedFile::fake()->createWithContent('konfiguration.json', $backup),
            'confirmation' => 'WIEDERHERSTELLEN',
        ]);

        $response->assertRedirect(route('configuration.system'))->assertSessionHasNoErrors();
        $this->assertSame('Gesicherter Verein', ClubSetting::current()->data['name']);
        $this->assertSame($logoPath, ClubSetting::current()->data['logo_path']);
        Storage::disk('local')->assertExists($logoPath);
        $this->assertSame($logo, Storage::disk('local')->get($logoPath));
        $this->assertSame($fieldCount, DB::table('member_field_definitions')->count());
        $this->assertDatabaseHas('security_audit_events', ['event' => 'configuration_backup_restore', 'outcome' => 'success']);
    }

    public function test_version_one_configuration_backup_preserves_previous_portal_access(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        $document = json_decode(app(ConfigurationBackup::class)->export(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($document['payload']['member_field_definitions'] as &$field) {
            unset($field['selfservice_visible']);
        }
        unset($field);
        $document['format_version'] = 1;
        $document['checksum'] = hash('sha256', json_encode(
            $document['payload'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $document['signature'] = app(BackupSignature::class)->sign($document['checksum']);

        DB::table('member_field_definitions')->update(['selfservice_visible' => false]);

        $response = $this->actingAs($admin)->post(route('configuration.backup.configuration.restore'), [
            'configuration_backup' => UploadedFile::fake()->createWithContent(
                'konfiguration-v1.json',
                json_encode($document, JSON_THROW_ON_ERROR),
            ),
            'confirmation' => 'WIEDERHERSTELLEN',
        ]);

        $response->assertRedirect(route('configuration.system'))->assertSessionHasNoErrors();
        $this->assertTrue((bool) DB::table('member_field_definitions')->where('key', 'gender')->value('selfservice_visible'));
        $this->assertFalse((bool) DB::table('member_field_definitions')->where('key', 'club_role')->value('selfservice_visible'));
    }

    public function test_configuration_restore_rejects_foreign_and_unsafe_backups(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        $restore = function (array $document) use ($admin) {
            return $this->actingAs($admin)->post(route('configuration.backup.configuration.restore'), [
                'configuration_backup' => UploadedFile::fake()->createWithContent('konfiguration.json', json_encode($document, JSON_THROW_ON_ERROR)),
                'confirmation' => 'WIEDERHERSTELLEN',
            ]);
        };
        $resign = function (array $document): array {
            $document['checksum'] = hash('sha256', json_encode($document['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $document['signature'] = app(BackupSignature::class)->sign($document['checksum']);

            return $document;
        };
        $original = json_decode(app(ConfigurationBackup::class)->export(), true, flags: JSON_THROW_ON_ERROR);

        $foreign = $original;
        $foreign['payload']['mail_settings']['from_name'] = 'Fremd';
        $foreign['checksum'] = hash('sha256', json_encode($foreign['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $restore($foreign)->assertSessionHasErrors('configuration_backup');

        $sendmail = $original;
        $sendmail['payload']['mail_settings']['driver'] = 'sendmail';
        $sendmail['payload']['mail_settings']['sendmail_path'] = "/bin/sh -c 'id > /tmp/pwned' #";
        $restore($resign($sendmail))->assertSessionHasErrors('configuration_backup');

        $field = $original;
        $field['payload']['member_field_definitions'][0]['key'] = "x') or 1=1 -- ";
        $restore($resign($field))->assertSessionHasErrors('configuration_backup');

        $this->assertNotSame('sendmail', DB::table('mail_settings')->value('driver'));
        $this->assertNotSame('Fremd', DB::table('mail_settings')->value('from_name'));
    }

    public function test_configuration_restore_rejects_wrong_confirmation_and_corrupt_backup(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);

        $this->actingAs($admin)->post(route('configuration.backup.configuration.restore'), [
            'configuration_backup' => UploadedFile::fake()->createWithContent('konfiguration.json', '{}'),
            'confirmation' => 'restore',
        ])->assertSessionHasErrors('confirmation');

        $this->actingAs($admin)->post(route('configuration.backup.configuration.restore'), [
            'configuration_backup' => UploadedFile::fake()->createWithContent('konfiguration.json', '{}'),
            'confirmation' => 'WIEDERHERSTELLEN',
        ])->assertSessionHasErrors('configuration_backup');
    }

    public function test_administrator_can_download_database_backup(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        $archive = storage_path('framework/testing/database-download-'.uniqid().'.zip');
        File::put($archive, 'archive');
        $this->mock(ApplicationBackup::class, function (MockInterface $mock) use ($archive): void {
            $mock->shouldReceive('create')
                ->once()
                ->with(true, storage_path('framework/cache'))
                ->andReturn($archive);
        });

        try {
            $this->actingAs($admin)
                ->get(route('configuration.backup.database.download'))
                ->assertOk()
                ->assertDownload(basename($archive));
        } finally {
            File::delete($archive);
        }
    }

    public function test_database_backup_failure_redirects_to_system_page_with_visible_error(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = User::factory()->create(['roles' => ['admin']]);
        $this->mock(ApplicationBackup::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')
                ->once()
                ->andThrow(new RuntimeException('mkdir(): Permission denied'));
        });

        $response = $this->actingAs($admin)->get(route('configuration.backup.database.download'));

        $response->assertRedirect(route('configuration.system'))
            ->assertSessionHas('backup_error', fn (string $message): bool => str_contains($message, 'Datenbanksicherung konnte nicht erstellt werden'));

        $this->actingAs($admin)
            ->get(route('configuration.system'))
            ->assertInertia(fn ($page) => $page
                ->where('backupError', fn (string $message): bool => str_contains($message, 'Datenbanksicherung konnte nicht erstellt werden')));
    }

    public function test_administrator_can_start_confirmed_database_restore(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        $this->mock(ApplicationBackup::class, function (MockInterface $mock): void {
            $mock->shouldReceive('restoreDatabase')
                ->once()
                ->withArgs(fn (string $path): bool => is_file($path))
                ->andReturn('/secure/backups/gymslunity-safety.zip');
        });

        $response = $this->actingAs($admin)->post(route('configuration.backup.database.restore'), [
            'database_backup' => UploadedFile::fake()->createWithContent('datenbank.zip', 'archive'),
            'confirmation' => 'WIEDERHERSTELLEN',
        ]);

        $response->assertRedirect(route('configuration.system'))->assertSessionHasNoErrors();
        $this->assertFileDoesNotExist(storage_path('framework/down'));
        $this->assertDatabaseHas('security_audit_events', ['event' => 'database_backup_restore', 'outcome' => 'success']);
    }

    public function test_non_administrators_cannot_access_backup_endpoints(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = User::factory()->create(['roles' => ['mv']]);
        foreach ([
            ['get', 'configuration.backup.configuration.download'],
            ['get', 'configuration.backup.database.download'],
            ['post', 'configuration.backup.configuration.restore'],
            ['post', 'configuration.backup.database.restore'],
        ] as [$method, $route]) {
            $this->actingAs($user)->{$method}(route($route))->assertForbidden();
        }
    }
}
