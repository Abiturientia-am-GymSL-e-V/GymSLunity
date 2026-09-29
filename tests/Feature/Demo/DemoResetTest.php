<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Demo\DemoAccounts;
use App\Models\ClubCalendarEvent;
use App\Models\ClubSetting;
use App\Models\Contribution;
use App\Models\ContributionTransaction;
use App\Models\Donation;
use App\Models\DonationCertificate;
use App\Models\FinanceInvoice;
use App\Models\InventoryItem;
use App\Models\Member;
use App\Models\Receipt;
use App\Models\ResourceBooking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        // As in production (AppServiceProvider).
        DB::prohibitDestructiveCommands();
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
        // Optional modules, created through the application services.
        $this->assertSame(0, ContributionTransaction::query()->where('kind', 'payment')->count());
        $this->assertGreaterThan(0, Contribution::query()->where('status', 'open')->count());
        $this->assertGreaterThan(0, Contribution::query()->where('status', 'paid')->count());
        $this->assertSame(3, FinanceInvoice::query()->count());
        $this->assertSame(4, Donation::query()->count());
        $this->assertSame(2, DonationCertificate::query()->count());
        $this->assertSame(5, InventoryItem::query()->count());
        $this->assertSame(6, ClubCalendarEvent::query()->count());
        $this->assertSame(1, ResourceBooking::query()->where('status', 'requested')->count());
        $this->assertSame(2, Receipt::query()->count());
        $this->assertFalse(app()->isDownForMaintenance());
    }
}
