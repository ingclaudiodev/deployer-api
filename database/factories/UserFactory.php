<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Give the user the role with this slug instead of the configured default.
     */
    public function role(string $slug): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::findBySlug($slug)->id,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->role(Role::SUPER_ADMIN);
    }

    public function admin(): static
    {
        return $this->role(Role::ADMIN);
    }

    public function usuario(): static
    {
        return $this->role(Role::USUARIO);
    }

    public function soporte(): static
    {
        return $this->role(Role::SOPORTE);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
