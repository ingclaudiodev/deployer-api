<?php

namespace App\Services\Projects\Deployment;

use App\Enums\ProjectFileAction;
use App\Enums\ProjectStatus;
use App\Exceptions\Projects\DeploymentException;
use App\Exceptions\Projects\ProjectStorageException;
use App\Exceptions\Repositories\RepositoryHostException;
use App\Exceptions\Repositories\TokenRefreshException;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\CloudProject;
use App\Services\Projects\Deployment\Contracts\DeploymentRunner;
use App\Services\Projects\ProjectAnalyzer;
use App\Services\Projects\ProjectProgress;
use App\Services\Projects\Storage\Contracts\ProjectFileStore;
use App\Services\Projects\Storage\ProjectFilePath;
use App\Services\Repositories\RepositoryConnector;
use Illuminate\Support\Facades\Cache;

/**
 * From a signed analysis to a running project. Approving freezes the files the
 * owner read into a snapshot nobody rewrites; the deployment then runs from
 * that snapshot, never from whatever a later analysis left in the bucket.
 */
class ProjectDeployer
{
    /**
     * What the runner executes: the repository as it is on its branch, with the
     * approved files laid over it, and then the scripts of the deployment. The
     * last lines leave the services of the project where Deployer can read them.
     */
    private const string SCRIPT = <<<'SH'
        set -euo pipefail
        mkdir -p /workspace/app && cd /workspace/app
        echo "::deployer:: 20 Descargando el código del repositorio"
        curl -fsSL "$SOURCE_URL" | tar -xz --strip-components=1
        echo "::deployer:: 28 Copiando los archivos aprobados sobre el código"
        gcloud storage rsync --recursive "gs://$BUCKET/$APPROVED_PREFIX" .
        echo "::deployer:: 35 Preparando la infraestructura del proyecto $PROJECT (deployer/setup.sh)"
        sh deployer/setup.sh
        echo "::deployer:: 60 Construyendo la imagen y desplegando (deployer/deploy.sh)"
        sh deployer/deploy.sh
        echo "::deployer:: 95 Despliegue terminado. Registrando los servicios"
        gcloud run services list --project="$PROJECT" --region="$REGION" --format=json > /workspace/services.json
        gcloud storage cp /workspace/services.json "gs://$BUCKET/$RESULT_OBJECT"
        SH;

    public function __construct(
        private readonly ProjectAnalyzer $analyzer,
        private readonly ProjectFileStore $files,
        private readonly RepositoryConnector $repositories,
        private readonly DeploymentRunner $runner,
        private readonly ProjectProgress $progress,
    ) {}

    /**
     * Record that the user signs the deployment of the last analysis and freeze
     * its files. The project is then being built.
     *
     * @throws DeploymentException|ProjectStorageException
     */
    public function approve(Project $project, User $user): Project
    {
        // A failed project can be signed again: the analysis on screen is still there, and
        // most failures are of the deployment, not of what was analysed.
        if (! in_array($project->status, [ProjectStatus::PendingApproval, ProjectStatus::Failed], true)) {
            throw DeploymentException::notApprovable('it has no analysis waiting for approval.');
        }

        $paths = $this->analyzer->stored($project)['files'] ?? null;

        if (! is_array($paths) || $paths === []) {
            throw DeploymentException::notApprovable('its analysis has no deployment files.');
        }

        $version = now()->format('Ymd\THis');

        foreach ($paths as $path) {
            $content = is_string($path) ? $this->files->get($project, ProjectFileAction::Deployment, $path) : null;

            if ($content === null) {
                throw DeploymentException::notApprovable("the file {$path} of its analysis is missing.");
            }

            $this->files->put($project, ProjectFileAction::Approved, "{$version}/{$path}", $content);
        }

        $project->forceFill([
            'status' => ProjectStatus::Building,
            'approved_at' => now(),
            'approved_by_id' => $user->id,
            'approved_version' => $version,
            'deployment_build_id' => null,
            'failure_reason' => null,
        ])->save();

        Cache::forget($this->cursorKey($project));
        $this->progress->begin($project, ProjectProgress::DEPLOYMENT, sprintf(
            'Aprobado por %s. %d archivos firmados y congelados (versión %s). Despliegue en cola…',
            $user->name ?: $user->email,
            count($paths),
            $version,
        ), 5);

        return $project;
    }

    /**
     * Hand the approved snapshot to the runner.
     *
     * @throws DeploymentException|RepositoryHostException|TokenRefreshException
     */
    public function start(Project $project): void
    {
        $version = (string) $project->approved_version;
        $bucket = (string) config('services.project_storage.bucket');
        $billing = (string) config('projects.platform.billing_account');

        if ($billing === '') {
            throw DeploymentException::notConfigured('DEPLOYER_BILLING_ACCOUNT');
        }

        $this->progress->step($project, ProjectProgress::DEPLOYMENT, "Pidiendo el código de {$project->repo_full_name} ({$project->repo_branch}) y lanzando el build aislado…", 10);

        $buildId = $this->runner->start(self::SCRIPT, [
            // A link of the host that expires in minutes and opens this archive only: the
            // user's token never leaves the API.
            'SOURCE_URL' => $this->repositories->archiveUrl($project->user, $project->repo_provider, $project->repo_full_name, $project->repo_branch),
            'BUCKET' => $bucket,
            'APPROVED_PREFIX' => ProjectFilePath::prefix($project, ProjectFileAction::Approved).$version,
            'RESULT_OBJECT' => ProjectFilePath::object($project, ProjectFileAction::Result, "{$version}.json"),
            'ORGANIZATION_ID' => (string) config('projects.platform.organization_id'),
            'BILLING_ACCOUNT' => $billing,
            'PROJECT' => CloudProject::idFor($project),
            'REGION' => CloudProject::regionFor($project),
            'VERSION' => strtolower($version),
        ], ['deployer', "project-{$project->id}"]);

        $project->forceFill(['deployment_build_id' => $buildId])->save();

        $this->progress->step($project, ProjectProgress::DEPLOYMENT, "Build {$buildId} lanzado en el proyecto de nube ".CloudProject::idFor($project).'. Esperando su salida…', 15);
    }

    /**
     * Look at the run of the deployment and move the project when it ended.
     * Returns whether it is still running.
     *
     * @throws DeploymentException|ProjectStorageException
     */
    public function follow(Project $project): bool
    {
        $this->relayOutput($project);

        $state = $this->runner->state((string) $project->deployment_build_id);

        if ($state === DeploymentState::Running) {
            return true;
        }

        if ($state === DeploymentState::Succeeded) {
            $project->forceFill(['status' => ProjectStatus::Live, 'url' => $this->publicUrl($project), 'failure_reason' => null])->save();
            $this->progress->step($project, ProjectProgress::DEPLOYMENT, $project->url === null ? 'Desplegado. El proyecto está en vivo.' : "Desplegado. En vivo en {$project->url}", 100);
        } else {
            $project->markFailed($this->runner->failure((string) $project->deployment_build_id));
            $this->progress->error($project, ProjectProgress::DEPLOYMENT, (string) $project->failure_reason);
        }

        return false;
    }

    /**
     * Pass on what the run printed since the last look. Not being able to read
     * the output must not stop the deployment from being followed.
     */
    private function relayOutput(Project $project): void
    {
        try {
            $output = $this->runner->output((string) $project->deployment_build_id, Cache::get($this->cursorKey($project)));
        } catch (DeploymentException $exception) {
            report($exception);

            return;
        }

        if ($output['cursor'] !== null) {
            Cache::put($this->cursorKey($project), $output['cursor'], now()->addDay());
        }

        $this->progress->output($project, ProjectProgress::DEPLOYMENT, $output['lines']);
    }

    private function cursorKey(Project $project): string
    {
        return "project-progress-cursor:{$project->id}";
    }

    /**
     * The address of the public service, from the list the deployment left: the
     * service the analysis marked public when it is there, else the first with an
     * address. `null` for a project that listens to nobody.
     */
    private function publicUrl(Project $project): ?string
    {
        $json = $this->files->get($project, ProjectFileAction::Result, "{$project->approved_version}.json");
        $services = $json === null ? null : json_decode($json, true);
        $public = collect($this->analyzer->stored($project)['services'] ?? [])->firstWhere('public', true)['name'] ?? null;
        $first = null;

        foreach (is_array($services) ? $services : [] as $service) {
            $url = $service['status']['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            if (($service['metadata']['name'] ?? null) === $public) {
                return $url;
            }

            $first ??= $url;
        }

        return $first;
    }
}
