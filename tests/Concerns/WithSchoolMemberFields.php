<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Database\Seeders\SchoolMemberFieldsSeeder;

/** Adds the school custom member fields, which new installations lack. */
trait WithSchoolMemberFields
{
    protected function setUpWithSchoolMemberFields(): void
    {
        $this->seed(SchoolMemberFieldsSeeder::class);
    }
}
