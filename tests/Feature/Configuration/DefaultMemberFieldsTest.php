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
        $this->assertFalse(MemberFieldDefinition::query()->where('is_custom', true)->whereNotIn('type', MemberFieldDefinition::TEMPORAL_TYPES)->exists());
        $this->assertContains('membership_type', $keys);
        // The former single-value functions are office fields with assignments.
        $this->assertSame('office', MemberFieldDefinition::query()->where('key', 'club_role')->value('type'));
        $this->assertSame('office', MemberFieldDefinition::query()->where('key', 'department_role')->value('type'));
    }
}
