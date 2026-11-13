<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\User;
use App\Services\Ai\Contracts\GenerativeModel;
use App\Services\Ai\VertexAiGenerativeModel;
use App\Services\CloudArchitectAgent;
use App\Services\Projects\Deployment\CloudBuildDeploymentRunner;
use App\Services\Projects\Deployment\Contracts\DeploymentRunner;
use App\Services\Projects\Storage\Contracts\ProjectFileStore;
use App\Services\Projects\Storage\GcsProjectFileStore;
use App\Services\Repositories\Adapters\GitHubTokenAdapter;
use App\Services\Repositories\Adapters\GitLabTokenAdapter;
use App\Services\Repositories\Hosts\GitHubHost;
use App\Services\Repositories\RefreshTokenService;
use App\Services\Repositories\RepositoryConnector;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GenerativeModel::class, function (): GenerativeModel {
            /** @var array{project: string|null, location: string, model: string, max_output_tokens: int, temperature: float, thinking_budget: int|string|null, timeout: int} $config */
            $config = config('services.vertex_ai');

            return new VertexAiGenerativeModel(
                project: $config['project'],
                location: $config['location'],
                model: $config['model'],
                maxOutputTokens: $config['max_output_tokens'],
                temperature: $config['temperature'],
                thinkingBudget: blank($config['thinking_budget']) ? null : (int) $config['thinking_budget'],
                timeoutSeconds: $config['timeout'],
            );
        });

        $this->app->bind(CloudArchitectAgent::class, fn (Application $app): CloudArchitectAgent => new CloudArchitectAgent(
            model: $app->make(GenerativeModel::class),
            maxFiles: (int) config('services.cloud_architect.max_files'),
            maxManifestCharacters: (int) config('services.cloud_architect.max_manifest_characters'),
        ));

        // One adapter per repository host; a new host is one more line here.
        $this->app->singleton(RefreshTokenService::class, fn (Application $app): RefreshTokenService => new RefreshTokenService([
            $app->make(GitHubTokenAdapter::class),
            $app->make(GitLabTokenAdapter::class),
        ]));

        // Hosts whose accounts can be connected. GitLab joins when its host is written.
        $this->app->singleton(RepositoryConnector::class, fn (Application $app): RepositoryConnector => new RepositoryConnector(
            hosts: [$app->make(GitHubHost::class)],
            tokens: $app->make(RefreshTokenService::class),
        ));

        $this->app->singleton(ProjectFileStore::class, fn (): ProjectFileStore => new GcsProjectFileStore(
            project: config('services.project_storage.project'),
            bucket: config('services.project_storage.bucket'),
        ));

        $this->app->singleton(DeploymentRunner::class, fn (): DeploymentRunner => new CloudBuildDeploymentRunner(
            project: config('services.deployments.project'),
            region: (string) config('services.deployments.region'),
            serviceAccount: config('services.deployments.service_account'),
            buildTimeoutSeconds: (int) config('services.deployments.timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->bindOwnedProjects();
    }

    /**
     * `{project}` in a route is always one of the signed-in user's projects. Bound
     * here and not in the routes file, which is skipped once routes are cached.
     */
    protected function bindOwnedProjects(): void
    {
        Route::bind('project', function (string $id): Project {
            $user = request()->user();

            abort_unless($user instanceof User, 404);

            return $user->projects()->findOrFail($id);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        JsonResource::withoutWrapping();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
