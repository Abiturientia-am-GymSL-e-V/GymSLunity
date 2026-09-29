<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Configuration\ConfigurationAudit;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_combines_protocols_filters_and_exports(): void
    {
        $auditor = User::factory()->create(['name' => 'Petra Prüfer', 'roles' => ['auditor']]);
        $admin = User::factory()->create(['name' => 'Anton Admin', 'roles' => ['admin']]);
        $member = Member::factory()->create();

        $this->actingAs($admin)->get(route('members.show', $member->member_number))->assertOk();
        ConfigurationAudit::record($admin, 'Vereinsdaten', ['name' => 'Alt'], ['name' => 'Neu']);

        $this->actingAs($auditor)->get(route('audit.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('audit/Index')
            ->where('entries.data', fn ($entries): bool => collect($entries)->contains(fn (array $entry): bool => $entry['action'] === 'Mitglied angesehen'
                && $entry['actor'] === 'Anton Admin'
                && $entry['subject'] === 'Mitglied '.$member->member_number)
                && collect($entries)->contains(fn (array $entry): bool => $entry['area'] === 'Konfiguration')));

        $this->get(route('audit.index', ['area' => 'configuration']))->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 1));
        $this->get(route('audit.index', ['search' => 'angesehen']))->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.action', 'Mitglied angesehen'));

        $csv = $this->get(route('audit.export', ['format' => 'csv']));
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Mitglied angesehen', $csv->streamedContent());
        $this->get(route('audit.export', ['format' => 'xlsx']))->assertOk();
        $this->assertStringStartsWith('%PDF-', $this->get(route('audit.export', ['format' => 'pdf']))->getContent());
    }

    public function test_audit_log_is_limited_to_admin_auditor_and_cash_audit(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]))->get(route('audit.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => ['kp']]))->get(route('audit.index'))->assertOk();
    }

    public function test_date_filter_uses_the_local_calendar_day(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        // 22:30 UTC on 10 June is 00:30 on 11 June in Berlin.
        $this->travelTo(CarbonImmutable::parse('2026-06-10 22:30:00', 'UTC'));
        ConfigurationAudit::record($admin, 'Vereinsdaten', ['name' => 'Alt'], ['name' => 'Neu']);
        $this->travelBack();

        $this->actingAs($admin);
        $this->get(route('audit.index', ['area' => 'configuration', 'from' => '2026-06-11', 'to' => '2026-06-11']))
            ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));
        $this->get(route('audit.index', ['area' => 'configuration', 'from' => '2026-06-10', 'to' => '2026-06-10']))
            ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0));
    }
}
