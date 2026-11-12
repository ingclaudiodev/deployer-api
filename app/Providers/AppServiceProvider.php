<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\User;
use App\Services\Ai\Contracts\GenerativeModel;
use App\Services\Ai\VertexAiGenerativeModel;
use App\Services\CloudArchitectAgent;
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
