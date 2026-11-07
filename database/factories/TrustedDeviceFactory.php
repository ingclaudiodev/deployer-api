<?php

namespace Database\Factories;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrustedDevice>
 */
class TrustedDeviceFactory extends Factory
{
    /** The plain token every factory-made device answers to. */
    public const TOKEN = 'factory-trusted-device-token';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => TrustedDevice::hashToken(self::TOKEN),
            'user_agent' => fake()->userAgent(),
            'ip_address' => fake()->ipv4(),
            'last_used_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => ['expires_at' => now()->subMinute()]);
    }
}
