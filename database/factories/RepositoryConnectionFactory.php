<?php

namespace Database\Factories;

use App\Enums\RepositoryProvider;
use App\Models\RepositoryConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryConnection>
 */
class RepositoryConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => RepositoryProvider::GitHub,
            'account_id' => (string) fake()->unique()->numberBetween(1000, 9_999_999),
            'account_login' => fake()->userName(),
            'access_token' => 'access-'.fake()->sha1(),
            'refresh_token' => 'refresh-'.fake()->sha1(),
            'access_token_expires_at' => now()->addHours(8),
            'refresh_token_expires_at' => now()->addMonths(6),
        ];
    }

    public function withGitHub(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => RepositoryProvider::GitHub,
        ]);
    }

    public function gitlab(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => RepositoryProvider::GitLab,
            'access_token_expires_at' => now()->addHours(2),
            'refresh_token_expires_at' => null,
        ]);
    }

    /**
     * The access token ran out; the refresh token is still good.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'access_token_expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * A token the host issued without a deadline, as GitHub does when expiry is off.
     */
    public function withoutExpiry(): static
    {
        return $this->state(fn (array $attributes): array => [
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ]);
    }
}
