<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrators_can_open_all_section_pages(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));

        foreach (['statistics', 'finance', 'forms', 'donations', 'inventory', 'kommunikation'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_section_pages_apply_their_role_permissions(): void
    {
        $permissions = [
            'statistics' => ['vereinsverwaltung', 'mv', 'auditor', 'bh', 'bv', 'kp'],
            'finance' => ['bh', 'kp'],
            'forms' => ['vereinsverwaltung', 'mv'],
            'donations' => ['bh'],
            'inventory' => ['vereinsverwaltung'],
            'kommunikation' => ['vereinsverwaltung', 'mv'],
        ];

        foreach ($permissions as $route => $allowedRoles) {
            foreach (['vereinsverwaltung', 'mv', 'auditor', 'bh', 'bv', 'kp'] as $role) {
                $this->actingAs(User::factory()->create(['roles' => [$role]]));
                $response = $this->get(route($route));

                in_array($role, $allowedRoles, true)
                    ? $response->assertOk()
                    : $response->assertForbidden();
            }
        }
    }

    public function test_inactive_users_cannot_open_section_pages(): void
    {
        $this->actingAs(User::factory()->create([
            'roles' => ['admin'],
            'is_active' => false,
        ]));

        foreach (['statistics', 'finance', 'forms', 'donations', 'inventory', 'kommunikation'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }
}
