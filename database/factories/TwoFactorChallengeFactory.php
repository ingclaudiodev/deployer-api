<?php

namespace Database\Factories;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<TwoFactorChallenge>
 */
class TwoFactorChallengeFactory extends Factory
{
    /** The plain code every factory-made challenge accepts. */
    public const CODE = '123456';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code_hash' => Hash::make(self::CODE),
            'attempts' => 0,
            'ip_address' => fake()->ipv4(),
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_sent_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (array $attributes): array => ['consumed_at' => now()]);
    }

    /** The last code was sent long enough ago for a resend to be allowed. */
    public function resendable(): static
    {
        return $this->state(fn (array $attributes): array => ['last_sent_at' => now()->subMinutes(2)]);
    }
}
