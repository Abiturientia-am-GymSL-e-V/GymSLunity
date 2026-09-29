<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\MemberFieldDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultMemberFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_installation_has_no_school_specific_fields(): void
    {
        $keys = MemberFieldDefinition::query()->pluck('key');

        $this->assertNotContains('custom_graduation', $keys);
        $this->assertNotContains('custom_graduation_year', $keys);
        $this->assertNotContains('custom_is_former_student', $keys);
        $this->assertFalse(MemberFieldDefinition::query()->where('is_custom', true)->exists());
        $this->assertContains('membership_type', $keys);
        $this->assertContains('club_role', $keys);
    }
}
