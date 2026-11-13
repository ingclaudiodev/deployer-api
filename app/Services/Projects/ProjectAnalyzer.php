<?php

namespace App\Services\Projects;

use App\Enums\ProjectFileAction;
use App\Enums\ProjectStatus;
use App\Exceptions\Ai\GenerativeModelException;
use App\Exceptions\Ai\InvalidAnalysisException;
use App\Exceptions\Projects\ProjectStorageException;
use App\Exceptions\Repositories\RepositoryHostException;
use App\Exceptions\Repositories\TokenRefreshException;
use App\Models\Project;
use App\Services\CloudArchitectAgent;
use App\Services\Projects\Storage\Contracts\ProjectFileStore;
use App\Services\Repositories\RepositoryConnector;

/**
 * Takes a project from "a repository" to "a deployment to approve": reads the
 * repository through the owner's connected account, has the architect design
 * the deployment and keeps the result in the project's space of the bucket.
 * The bucket is the source of truth; a branch in the repository is a copy.
 *
 * @phpstan-import-type Analysis from CloudArchitectAgent
 * @phpstan-import-type TargetContext from CloudArchitectAgent
 */
class ProjectAnalyzer
{
    /** Name of the analysis inside the `analisis` action. */
    public const string ANALYSIS_FILE = 'analisis.json';

    /**
     * Files worth reading in full, by name: manifests, entry points, and process
     * and build configuration. They are what lets the architect tell the
     * components of the codebase apart; the rest of the tree goes as paths.
     */
    private const array KEY_FILES = [
        'package.json', 'composer.json', 'requirements.txt', 'pyproject.toml', 'Pipfile', 'go.mod', 'Cargo.toml',
        'Gemfile', 'pom.xml', 'build.gradle', 'build.gradle.kts', 'Dockerfile', '.dockerignore', 'Procfile',
        'docker-compose.yml', 'docker-compose.yaml', 'compose.yaml', 'compose.yml', 'Makefile', '.env.example',
        'supervisord.conf', 'nginx.conf', 'entrypoint.sh', 'start.sh',
        'artisan', 'manage.py', 'wsgi.py', 'asgi.py', 'main.py', 'app.py', 'main.go', 'server.js', 'index.js',
        'main.ts', 'server.ts', 'vite.config.ts', 'vite.config.js', 'next.config.js', 'next.config.mjs',
        'nuxt.config.ts', 'angular.json', 'horizon.php', 'queue.php', 'console.php', 'Kernel.php', 'schema.prisma',
    ];

    /** Dependencies and build output: never the application's own code. */
    private const string IGNORED = '#(^|/)(node_modules|vendor|dist|build|\.git|\.next|target|__pycache__)/#';

    /** Deeper than this a file with a known name is rarely an entry point. */
    private const int MAX_DEPTH = 3;

    /** Each file is one request to the host and tokens billed by the model. */
    private const int MAX_KEY_FILES = 80;

    public function __construct(
        private readonly RepositoryConnector $repositories,
        private readonly CloudArchitectAgent $agent,
        private readonly ProjectFileStore $files,
        private readonly ProjectProgress $progress,
    ) {}

    /**
     * Analyse the repository of the project and store the deployment it needs.
     * The project then waits for its owner to approve it.
     *
     * @return Analysis
     *
     * @throws RepositoryHostException|TokenRefreshException|GenerativeModelException|InvalidAnalysisException|ProjectStorageException
     */
    public function analyze(Project $project): array
    {
        $user = $project->user;
        $provider = $project->repo_provider;
        $repository = $project->repo_full_name;
        $branch = $project->repo_branch;

        $phase = ProjectProgress::ANALYSIS;
        $this->progress->step($project, $phase, "Leyendo el árbol de {$repository} en la rama {$branch}…", 5);

        $paths = $this->repositories->tree($user, $provider, $repository, $branch);
        $keyFiles = $this->keyFiles($paths);
        $manifests = [];

        $this->progress->step($project, $phase, count($paths).' archivos en el repositorio. Leyendo '.count($keyFiles).' archivos clave (manifiestos, puntos de entrada, configuración)…', 12);

        foreach ($keyFiles as $path) {
            $content = $this->repositories->file($user, $provider, $repository, $branch, $path);

            if ($content !== null) {
                $manifests[$path] = $content;
            }
        }

        $this->progress->step($project, $phase, 'Código leído. El arquitecto está razonando sobre los componentes y redactando el despliegue: suele tardar de uno a cinco minutos…', 25);

        $analysis = $this->agent->analyze([
            'repository' => $repository,
            'default_branch' => $branch,
            'files' => $paths,
            'manifests' => $manifests,
            'target' => $this->target($project),
        ]);

        $this->progress->step($project, $phase, sprintf(
            'Análisis recibido: %s. %d componentes, %d procesos, %d archivos de despliegue.',
            implode(' · ', array_filter([$analysis['stack']['language'], $analysis['stack']['framework']])),
            count($analysis['components']),
            count($analysis['services']),
            count($analysis['files']),
        ), 80);

        foreach ($analysis['components'] as $component) {
            $this->progress->output($project, $phase, ["  {$component['name']} ({$component['path']}) → {$component['runs_as']}"]);
        }

        $this->progress->step($project, $phase, 'Guardando los archivos de despliegue…', 90);

        foreach ($analysis['files'] as $file) {
            $this->files->put($project, ProjectFileAction::Deployment, $file['path'], $file['content']);
        }

        // Written last: it lists the files of this analysis, so a reader never sees
        // an analysis whose files are not all in place yet.
        $this->files->put($project, ProjectFileAction::Analysis, self::ANALYSIS_FILE, (string) json_encode([
            ...$analysis,
            'files' => array_column($analysis['files'], 'path'),
            'repository' => $repository,
            'branch' => $branch,
            'analyzed_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $project->forceFill(['status' => ProjectStatus::PendingApproval, 'failure_reason' => null])->save();

        $this->progress->step($project, $phase, 'Listo. Revisa el análisis y los archivos, y aprueba para desplegar.', 100);

        return $analysis;
    }

    /**
     * Where and within which limits the project is to run. Unlike the repository
     * this comes from Deployer and from what the owner chose, so the architect
     * takes it as given.
     *
     * @return TargetContext
     */
    private function target(Project $project): array
    {
        return [
            'organization_id' => (string) config('projects.platform.organization_id'),
            'project_id' => CloudProject::idFor($project),
            'region' => CloudProject::regionFor($project),
            'kind' => $project->kind->value,
            'limits' => array_filter([
                'cpu' => $project->cpu,
                'memory_mb' => $project->memory_mb,
                'min_instances' => $project->min_instances,
                'max_instances' => $project->max_instances,
                'concurrency' => $project->concurrency,
                'timeout_seconds' => $project->timeout_seconds,
                'port' => $project->port,
                'public' => $project->public_access,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * The files of the tree to read in full, shallowest first, so the root of
     * the repository is always covered before a nested application.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function keyFiles(array $paths): array
    {
        $chosen = array_filter($paths, fn (string $path): bool => in_array(basename($path), self::KEY_FILES, true)
            && substr_count($path, '/') <= self::MAX_DEPTH
            && preg_match(self::IGNORED, $path) !== 1);

        usort($chosen, fn (string $a, string $b): int => [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b]);

        return array_slice($chosen, 0, self::MAX_KEY_FILES);
    }

    /**
     * The last analysis stored for the project, with `files` as paths; `null`
     * when it was never analysed.
     *
     * @return array<string, mixed>|null
     *
     * @throws ProjectStorageException
     */
    public function stored(Project $project): ?array
    {
        $json = $this->files->get($project, ProjectFileAction::Analysis, self::ANALYSIS_FILE);
        $analysis = $json === null ? null : json_decode($json, true);

        return is_array($analysis) ? $analysis : null;
    }

    /**
     * Content of a deployment file of the last analysis; `null` for a path that
     * analysis does not list, whatever else the bucket holds.
     *
     * @throws ProjectStorageException
     */
    public function file(Project $project, string $path): ?string
    {
        $paths = $this->stored($project)['files'] ?? [];

        return is_array($paths) && in_array($path, $paths, true)
            ? $this->files->get($project, ProjectFileAction::Deployment, $path)
            : null;
    }

    /**
     * Copy the deployment files of the last analysis to a branch of the
     * repository, next to the branch the project deploys. Returns its name, or
     * `null` when there is nothing to publish yet.
     *
     * @throws RepositoryHostException|TokenRefreshException|ProjectStorageException
     */
    public function publishBranch(Project $project): ?string
    {
        $paths = $this->stored($project)['files'] ?? null;
        $files = [];

        foreach (is_array($paths) ? $paths : [] as $path) {
            $content = is_string($path) ? $this->files->get($project, ProjectFileAction::Deployment, $path) : null;

            if ($content !== null) {
                $files[] = ['path' => $path, 'content' => $content];
            }
        }

        if ($files === []) {
            return null;
        }

        $branch = self::branchOf($project);

        $this->repositories->publishBranch(
            $project->user,
            $project->repo_provider,
            $project->repo_full_name,
            $project->repo_branch,
            $branch,
            $files,
            "Deployer: archivos de despliegue de {$project->name}",
        );

        return $branch;
    }

    /**
     * The branch Deployer writes for a project. Its name is fixed, so publishing
     * again moves the same branch instead of piling up new ones.
     */
    public static function branchOf(Project $project): string
    {
        return "deployer/{$project->name}";
    }
}
