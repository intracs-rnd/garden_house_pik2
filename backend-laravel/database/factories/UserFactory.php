<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'name'              => $this->faker->name(),
            'email'             => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => Hash::make('password'),
            'role'              => 'user',
            'phone'             => $this->faker->numerify('08##########'),
            'no_kk'             => $this->faker->unique()->numerify('################'),
            'is_active'         => true,
            'remember_token'    => Str::random(10),
        ];
    }

    /**
     * Indicate that the user is an administrator.
     */
    public function admin()
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin']);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified()
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }
}
