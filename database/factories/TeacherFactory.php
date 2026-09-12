<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Teacher>
 */
class TeacherFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'institutional_code' => fake()->unique()->bothify('DOC-####'),
            'identity_number' => fake()->unique()->numerify('#######'),
            'first_names' => fake()->firstName(),
            'last_names' => fake()->lastName(),
            'status' => UserStatus::ACTIVE->value,
        ];
    }
}
