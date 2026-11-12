<?php

namespace Database\Factories;

use App\Enums\ProjectBilling;
use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\RepositoryProvider;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'api-'.fake()->unique()->numerify('####'),
            'kind' => ProjectKind::Service,
            'billing' => ProjectBilling::Request,
            'repo_provider' => RepositoryProvider::GitHub,
            'repo_full_name' => 'acme/'.fake()->slug(2),
            'repo_branch' => 'main',
            'region' => 'norteamerica',
            'cpu' => '1',
            'memory_mb' => 512,
            'min_instances' => 0,
            'max_instances' => 2,
            'concurrency' => 80,
            'timeout_seconds' => 300,
            'port' => 8080,
            'public_access' => true,
        ];
    }

    public function job(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => ProjectKind::Job,
            'billing' => null,
            'min_instances' => null,
            'max_instances' => null,
            'concurrency' => null,
            'port' => null,
            'public_access' => null,
            'timeout_seconds' => 600,
            'tasks' => 1,
            'max_retries' => 3,
        ]);
    }

    public function workerPool(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => ProjectKind::WorkerPool,
            'billing' => null,
            'min_instances' => null,
            'max_instances' => 1,
            'concurrency' => null,
            'timeout_seconds' => null,
            'port' => null,
            'public_access' => null,
        ]);
    }

    public function live(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Live,
            'url' => 'https://'.$attributes['name'].'.deployer.test',
        ]);
    }
}
