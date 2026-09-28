<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Member> */
class MemberFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'member_number' => fake()->unique()->numberBetween(1000000, 9999999),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->userName().'@example.invalid',
            'city' => 'Musterstadt',
            'postal_code' => '01234',
            'country' => 'Deutschland',
            'membership_type' => 'Ehemalige',
            'custom_values' => ['custom_graduation_year' => 2010, 'custom_graduation' => null, 'custom_is_former_student' => false],
            'joined_at' => '2025-01-01',
        ];
    }
}
