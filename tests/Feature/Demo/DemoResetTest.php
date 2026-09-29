<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Demo\DemoAccounts;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * migrate:fresh cannot run inside the transaction of RefreshDatabase; the
 * in-memory test database is discarded with the application instead.
 */
class DemoResetTest extends TestCase
{
    public function test_reset_replaces_all_data_with_the_demo_club(): void
    {
        config(['demo.enabled' => true, 'demo.password' => 'Geteiltes-Passwort']);
        $this->artisan('migrate')->assertSuccessful();
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('documents/visitor.pdf', 'upload');
        Storage::disk('public')->put('visitor.txt', 'upload');
        $visitorMember = Member::factory()->create(['email' => 'besucher@example.org']);

        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertDatabaseMissing('members', ['email' => $visitorMember->email]);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame('Turnverein Musterstadt e. V.', ClubSetting::current()->data['name']);
        $this->assertTrue(Member::query()->where('email', DemoAccounts::MEMBER_EMAIL)->exists());
        $this->assertSame(40, Member::query()->count());
        foreach (DemoAccounts::USERS as $account) {
            $user = User::query()->where('email', $account['email'])->firstOrFail();
            $this->assertSame($account['roles'], $user->roles);
            $this->assertTrue(Hash::check('Geteiltes-Passwort', $user->password));
            $this->assertTrue($user->hasVerifiedEmail());
        }
        $this->assertFalse(app()->isDownForMaintenance());
    }
}
