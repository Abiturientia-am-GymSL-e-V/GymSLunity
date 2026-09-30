<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberAssignment;
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

    /** Assigns an option of a department, office or honor field. */
    public function withAssignment(string $field, string $option, ?string $from = null, ?string $to = null): static
    {
        return $this->afterCreating(fn (Member $member) => MemberAssignment::query()->create([
            'member_id' => $member->id, 'field_key' => $field, 'option_value' => $option, 'starts_on' => $from, 'ends_on' => $to,
        ]));
    }
}
