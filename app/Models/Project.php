<?php

namespace App\Models;

use App\Enums\ProjectBilling;
use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\RepositoryProvider;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A repository the user wants running as a service, with the compute limits
 * they chose for it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property ProjectKind $kind
 * @property ProjectBilling|null $billing Only services choose how they are charged.
 * @property RepositoryProvider $repo_provider
 * @property string $repo_full_name
 * @property string $repo_branch
 * @property string $region
 * @property string $cpu
 * @property int $memory_mb
 * @property string|null $gpu GPU type each instance carries, as `config('projects.gpu.types')` names it.
 * @property bool|null $gpu_zonal_redundancy
 * @property int|null $ephemeral_disk_gb
 * @property string|null $ephemeral_disk_path Where the ephemeral disk is mounted.
 * @property int|null $min_instances Services only.
 * @property int|null $max_instances Services; for a worker pool, its fixed number of instances.
 * @property int|null $concurrency Services only.
 * @property int|null $timeout_seconds Of a request (services) or of a task (jobs).
 * @property int|null $tasks Jobs only.
 * @property int|null $max_retries Jobs only.
 * @property bool $delayed Jobs only: wait for spare capacity, at a lower price.
 * @property int|null $port Kinds that listen: services and instances.
 * @property bool|null $public_access Kinds that listen: services and instances.
 * @property ProjectStatus $status
 * @property string|null $url
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
#[Fillable([
    'name', 'kind', 'billing', 'tasks', 'max_retries', 'delayed', 'repo_provider', 'repo_full_name', 'repo_branch', 'region', 'cpu', 'memory_mb',
    'min_instances', 'max_instances', 'concurrency', 'timeout_seconds', 'port', 'public_access',
    'gpu', 'gpu_zonal_redundancy', 'ephemeral_disk_gb', 'ephemeral_disk_path',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Nothing has been built for a project that was just created. The status is
     * not mass-assignable: only the deploy flow moves it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ProjectStatus::NotDeployed->value,
        'kind' => ProjectKind::Service->value,
        'delayed' => false,
    ];

    /**
     * Only a service chooses how it is charged; the database default (per request)
     * is for services, so every other kind drops it.
     */
    protected static function booted(): void
    {
        static::saving(function (Project $project): void {
            if ($project->kind !== ProjectKind::Service) {
                $project->billing = null;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ProjectKind::class,
            'billing' => ProjectBilling::class,
            'tasks' => 'integer',
            'max_retries' => 'integer',
            'delayed' => 'boolean',
            'repo_provider' => RepositoryProvider::class,
            'status' => ProjectStatus::class,
            'memory_mb' => 'integer',
            'min_instances' => 'integer',
            'max_instances' => 'integer',
            'concurrency' => 'integer',
            'timeout_seconds' => 'integer',
            'port' => 'integer',
            'public_access' => 'boolean',
            'gpu_zonal_redundancy' => 'boolean',
            'ephemeral_disk_gb' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
