<?php

use App\Enums\ProjectStatus;
use App\Jobs\DeployProject;
use App\Jobs\WatchProjectDeployment;
use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Models\User;
use App\Services\Projects\Deployment\Contracts\DeploymentRunner;
use App\Services\Projects\Deployment\DeploymentState;
use App\Services\Projects\Deployment\ProjectDeployer;
use App\Events\Projects\ProjectProgressed;
use App\Exceptions\Projects\DeploymentException;
use App\Services\Projects\ProjectAnalyzer;
use App\Services\Projects\ProjectProgress;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

beforeEach(function (): void {
    Http::preventStrayRequests();

    config([
        'projects.platform.organization_id' => '274265040353',
        'projects.platform.billing_account' => '000000-AAAAAA-111111',
        'projects.platform.project_prefix' => 'dpl',
        'services.project_storage.bucket' => 'deployer-bucket',
    ]);
});

/**
 * Stands in for Cloud Build: keeps what it was asked to run and answers with the given state.
 */
function fakeDeploymentRunner(DeploymentState $state = DeploymentState::Running): DeploymentRunner
{
    $runner = new class($state) implements DeploymentRunner
    {
        public ?string $script = null;

        /** @var array<string, string> */
        public array $environment = [];

        public function __construct(public DeploymentState $state) {}

        public function start(string $script, array $environment, array $tags = []): string
        {
            $this->script = $script;
            $this->environment = $environment;

            return 'build-1';
        }

        public function state(string $runId): DeploymentState
        {
            return $this->state;
        }

        /** @var list<string> */
        public array $lines = [];

        public bool $unreadable = false;

        public function output(string $runId, ?string $cursor): array
        {
            if ($this->unreadable) {
                throw DeploymentException::unexpectedAnswer(403, 'Permission logging.logEntries.list denied');
            }

            // Like the real one: only what is new since the cursor.
            return ['lines' => $cursor === null ? $this->lines : [], 'cursor' => 'seen'];
        }

        public function failure(string $runId): string
        {
            return "Build {$runId} ended as FAILURE. The script exited with code 1.";
        }
    };

    app()->instance(DeploymentRunner::class, $runner);

    return $runner;
}

/**
 * A project of user 1 whose repository was analysed: it waits for approval.
 */
function analysedProject(): Project
{
    $user = User::factory()->create(['id' => 1]);
    RepositoryConnection::factory()->for($user)->create(['access_token' => 'gho-access']);
    $project = Project::factory()->for($user)->create([
        'name' => 'orders-api',
        'repo_full_name' => 'octocat/orders-api',
        'repo_branch' => 'main',
    ]);

    fakeGenerativeModel(analysisJson());
    Http::fake([
        'https://api.github.com/repos/octocat/orders-api/branches/main' => Http::response([
            'commit' => ['sha' => 'commit-1', 'commit' => ['tree' => ['sha' => 'tree-1']]],
        ]),
        'https://api.github.com/repos/octocat/orders-api/git/trees/tree-1*' => Http::response(['tree' => [['path' => 'package.json', 'type' => 'blob']]]),
        'https://api.github.com/repos/octocat/orders-api/contents/package.json*' => Http::response('{"name":"orders-api"}'),
        'https://api.github.com/repos/octocat/orders-api/tarball/main' => Http::response('', 302, ['Location' => 'https://codeload.github.com/octocat/orders-api/legacy.tar.gz/main?token=short-lived']),
    ]);
    app(ProjectAnalyzer::class)->analyze($project);

    return $project->fresh();
}

test('approving freezes the signed files, records who signed and queues the deployment', function (): void {
    Queue::fake();
    $this->freezeSecond();
    $store = fakeProjectFileStore();
    $project = analysedProject();
    Passport::actingAs($project->user);

    $this->postJson(route('projects.approval.store', $project))
        ->assertOk()
        ->assertJsonPath('estado', 'construyendo');

    $project->refresh();
    $version = now()->format('Ymd\THis');

    expect($project->approved_by_id)->toBe(1)
        ->and($project->approved_at->equalTo(now()))->toBeTrue()
        ->and($project->approved_version)->toBe($version)
        ->and($store->objects["1/orders-api/aprobado/{$version}/Dockerfile"])->toStartWith('FROM node:22-alpine')
        ->and($store->objects["1/orders-api/aprobado/{$version}/deployer/deploy.sh"])->toBe('#!/bin/sh');

    Queue::assertPushed(DeployProject::class, fn (DeployProject $job): bool => $job->project->is($project));
});

test('a later analysis does not change what was approved', function (): void {
    Queue::fake();
    $store = fakeProjectFileStore();
    $project = analysedProject();
    app(ProjectDeployer::class)->approve($project, $project->user);
    $version = $project->approved_version;

    $store->objects['1/orders-api/despliegue/Dockerfile'] = 'FROM scratch';

    expect($store->objects["1/orders-api/aprobado/{$version}/Dockerfile"])->toStartWith('FROM node:22-alpine');
});

test('only a project waiting for approval can be approved', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    $project = Project::factory()->create();
    Passport::actingAs($project->user);

    $this->postJson(route('projects.approval.store', $project))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('estado');

    expect($project->fresh()->status)->toBe(ProjectStatus::NotDeployed);
    Queue::assertNothingPushed();
});

test('nobody approves the project of somebody else', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    $project = analysedProject();
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.approval.store', $project))->assertNotFound();

    expect($project->fresh()->status)->toBe(ProjectStatus::PendingApproval);
});

test('the deployment runs the approved snapshot in the cloud project of the project, without the token of the account', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    $runner = fakeDeploymentRunner();
    $project = analysedProject();
    app(ProjectDeployer::class)->approve($project, $project->user);

    (new DeployProject($project))->handle(app(ProjectDeployer::class));

    $version = $project->fresh()->approved_version;

    expect($runner->environment)->toMatchArray([
        'SOURCE_URL' => 'https://codeload.github.com/octocat/orders-api/legacy.tar.gz/main?token=short-lived',
        'BUCKET' => 'deployer-bucket',
        'APPROVED_PREFIX' => "1/orders-api/aprobado/{$version}",
        'RESULT_OBJECT' => "1/orders-api/resultado/{$version}.json",
        'ORGANIZATION_ID' => '274265040353',
        'BILLING_ACCOUNT' => '000000-AAAAAA-111111',
        'PROJECT' => 'dpl-1-orders-api',
        'REGION' => 'us-central1',
    ])
        ->and(implode(' ', $runner->environment))->not->toContain('gho-access')
        ->and($runner->script)->toContain('sh deployer/setup.sh', 'sh deployer/deploy.sh')
        ->and($project->fresh()->deployment_build_id)->toBe('build-1');

    Queue::assertPushed(WatchProjectDeployment::class);
});

test('a deployment that cannot start leaves the project failed', function (): void {
    $project = Project::factory()->create();

    (new DeployProject($project))->failed(new RuntimeException('boom'));

    expect($project->fresh()->status)->toBe(ProjectStatus::Failed);
});

test('a deployment still running is looked at again later', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    fakeDeploymentRunner(DeploymentState::Running);
    $project = Project::factory()->create(['deployment_build_id' => 'build-1']);
    $project->forceFill(['status' => ProjectStatus::Building])->save();

    (new WatchProjectDeployment($project))->handle(app(ProjectDeployer::class));

    expect($project->fresh()->status)->toBe(ProjectStatus::Building);
    Queue::assertPushed(WatchProjectDeployment::class, fn (WatchProjectDeployment $job): bool => $job->look === 2 && $job->buildId === 'build-1');
});

test('a deployment that succeeded leaves the project live at the address of its public service', function (): void {
    Queue::fake();
    $store = fakeProjectFileStore();
    fakeDeploymentRunner(DeploymentState::Succeeded);
    $project = analysedProject();
    app(ProjectDeployer::class)->approve($project, $project->user);
    $project->forceFill(['deployment_build_id' => 'build-1'])->save();
    $store->objects["1/orders-api/resultado/{$project->approved_version}.json"] = (string) json_encode([
        ['metadata' => ['name' => 'worker'], 'status' => ['url' => 'https://worker.example.test']],
        ['metadata' => ['name' => 'orders-api'], 'status' => ['url' => 'https://orders-api.example.test']],
    ]);

    (new WatchProjectDeployment($project))->handle(app(ProjectDeployer::class));

    expect($project->fresh())
        ->status->toBe(ProjectStatus::Live)
        ->url->toBe('https://orders-api.example.test');
    Queue::assertNotPushed(WatchProjectDeployment::class);
});

test('a deployment that failed leaves the project failed', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    fakeDeploymentRunner(DeploymentState::Failed);
    $project = Project::factory()->create(['deployment_build_id' => 'build-1']);
    $project->forceFill(['status' => ProjectStatus::Building])->save();

    (new WatchProjectDeployment($project))->handle(app(ProjectDeployer::class));

    expect($project->fresh())
        ->status->toBe(ProjectStatus::Failed)
        ->failure_reason->toBe('Build build-1 ended as FAILURE. The script exited with code 1.');
    Queue::assertNotPushed(WatchProjectDeployment::class);
});

test('the owner reads why the project failed and can approve again, which clears it', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    $project = analysedProject();
    Passport::actingAs($project->user);

    (new DeployProject($project))->failed(new RuntimeException('no billing account'));

    $this->getJson(route('projects.show', $project))
        ->assertOk()
        ->assertJsonPath('estado', 'fallido')
        ->assertJsonPath('motivoFallo', 'The deployment could not start: no billing account');

    // No new analysis is needed to try again: the one on screen is signed once more.
    $this->postJson(route('projects.approval.store', $project))
        ->assertOk()
        ->assertJsonPath('estado', 'construyendo')
        ->assertJsonPath('motivoFallo', null);
});

test('the watcher of an approval that was replaced stops', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    fakeDeploymentRunner(DeploymentState::Failed);
    $project = Project::factory()->create(['deployment_build_id' => 'build-2']);
    $project->forceFill(['status' => ProjectStatus::Building])->save();

    (new WatchProjectDeployment($project, 'build-1'))->handle(app(ProjectDeployer::class));

    expect($project->fresh()->status)->toBe(ProjectStatus::Building);
    Queue::assertNothingPushed();
});

test('the analysis tells what it does, step by step, up to one hundred percent', function (): void {
    Queue::fake();
    Event::fake([ProjectProgressed::class]);
    fakeProjectFileStore();
    $project = analysedProject();
    Passport::actingAs($project->user);

    $entries = $this->getJson(route('projects.progress', $project))->assertOk()->json('entradas');

    expect(array_column($entries, 'fase'))->each->toBe('analisis')
        ->and(array_column($entries, 'porcentaje'))->toBe(collect($entries)->pluck('porcentaje')->sort()->values()->all())
        ->and(end($entries)['porcentaje'])->toBe(100)
        ->and(collect($entries)->where('tipo', 'log')->pluck('mensaje')->all())->toContain('  api (.) → service');

    Event::assertDispatched(ProjectProgressed::class, fn (ProjectProgressed $event): bool => $event->projectId === $project->id
        && $event->userId === $project->user_id
        && $event->broadcastAs() === 'project.progress');
});

test('the deployment relays the output of the build once, its marks as steps with their percentage', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    $runner = fakeDeploymentRunner(DeploymentState::Running);
    $runner->lines = ['::deployer:: 35 Preparando la infraestructura', 'Created project [dpl-1-orders-api].', ''];
    $project = analysedProject();
    $deployer = app(ProjectDeployer::class);
    $deployer->approve($project, $project->user);
    $project->forceFill(['deployment_build_id' => 'build-1'])->save();

    (new WatchProjectDeployment($project))->handle($deployer);
    (new WatchProjectDeployment($project))->handle($deployer);

    $entries = app(ProjectProgress::class)->entries($project);

    expect(array_column($entries, 'tipo'))->toBe(['paso', 'paso', 'log'])
        ->and(array_column($entries, 'porcentaje'))->toBe([5, 35, 35])
        ->and($entries[2]['mensaje'])->toBe('Created project [dpl-1-orders-api].');
});

test('a new approval starts the diary again, and the outcome closes it', function (): void {
    Queue::fake();
    $store = fakeProjectFileStore();
    fakeDeploymentRunner(DeploymentState::Succeeded);
    $project = analysedProject();
    $deployer = app(ProjectDeployer::class);
    $deployer->approve($project, $project->user);
    $project->forceFill(['deployment_build_id' => 'build-1'])->save();
    $store->objects["1/orders-api/resultado/{$project->approved_version}.json"] = (string) json_encode([
        ['metadata' => ['name' => 'orders-api'], 'status' => ['url' => 'https://orders-api.example.test']],
    ]);

    (new WatchProjectDeployment($project))->handle($deployer);

    $entries = app(ProjectProgress::class)->entries($project);

    expect(array_column($entries, 'fase'))->each->toBe('despliegue')
        ->and(end($entries))->toMatchArray(['tipo' => 'paso', 'porcentaje' => 100, 'mensaje' => 'Desplegado. En vivo en https://orders-api.example.test']);
});

test('nobody reads the progress of the project of somebody else', function (): void {
    fakeProjectFileStore();
    $project = analysedProject();
    Passport::actingAs(User::factory()->create());

    $this->getJson(route('projects.progress', $project))->assertNotFound();
});

test('a deployment whose output cannot be read says so once and is still followed', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    $runner = fakeDeploymentRunner(DeploymentState::Running);
    $runner->unreadable = true;
    $project = analysedProject();
    $deployer = app(ProjectDeployer::class);
    $deployer->approve($project, $project->user);
    $project->forceFill(['deployment_build_id' => 'build-1'])->save();

    (new WatchProjectDeployment($project))->handle($deployer);
    (new WatchProjectDeployment($project))->handle($deployer);

    $lines = collect(app(ProjectProgress::class)->entries($project))->where('tipo', 'log')->pluck('mensaje');

    expect($lines)->toHaveCount(1)
        ->and($lines->first())->toContain('No se puede leer la salida del build', 'logging.logEntries.list')
        ->and($project->fresh()->status)->toBe(ProjectStatus::Building);
    Queue::assertPushed(WatchProjectDeployment::class, 2);
});

test('asking for the progress moves a running deployment forward, without waiting for the queue', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    $runner = fakeDeploymentRunner(DeploymentState::Failed);
    $runner->lines = ['ERROR: (gcloud.builds.submit) INVALID_ARGUMENT'];
    $project = analysedProject();
    app(ProjectDeployer::class)->approve($project, $project->user);
    $project->forceFill(['deployment_build_id' => 'build-1'])->save();
    Passport::actingAs($project->user);

    $response = $this->getJson(route('projects.progress', $project))->assertOk()->assertJsonPath('estado', 'fallido');

    expect(collect($response->json('entradas'))->pluck('tipo')->all())->toBe(['paso', 'log', 'error'])
        ->and($project->fresh()->failure_reason)->toContain('build-1');
});

test('looks that keep failing end the deployment as failed instead of leaving it building', function (): void {
    Queue::fake();
    fakeProjectFileStore();
    $project = Project::factory()->create(['deployment_build_id' => 'build-1']);
    $project->forceFill(['status' => ProjectStatus::Building])->save();

    (new WatchProjectDeployment($project, 'build-1', 3, 0))->failed(new RuntimeException('boom'));

    expect($project->fresh()->status)->toBe(ProjectStatus::Building);
    Queue::assertPushed(WatchProjectDeployment::class, fn (WatchProjectDeployment $job): bool => $job->failedLooks === 1 && $job->look === 4);

    (new WatchProjectDeployment($project, 'build-1', 8, 5))->failed(new RuntimeException('boom'));

    expect($project->fresh())
        ->status->toBe(ProjectStatus::Failed)
        ->failure_reason->toBe('Deployer could not follow the deployment: boom');
});

