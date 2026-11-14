<?php

use App\Enums\ProjectStatus;
use App\Jobs\AnalyzeProjectRepository;
use App\Models\Project;
use App\Models\RepositoryConnection;
use App\Models\User;
use App\Services\Projects\ProjectAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

beforeEach(function (): void {
    Http::preventStrayRequests();

    config(['projects.platform.organization_id' => '274265040353', 'projects.platform.project_prefix' => 'dpl']);
});

/**
 * A project of a user with a GitHub account connected.
 */
function connectedProject(): Project
{
    // Its cloud project is `dpl-1-orders-api`, the one the analysis of the tests deploys to.
    $user = User::factory()->create(['id' => 1]);
    RepositoryConnection::factory()->for($user)->create(['access_token' => 'gho-access']);

    return Project::factory()->for($user)->create([
        'name' => 'orders-api',
        'repo_full_name' => 'octocat/orders-api',
        'repo_branch' => 'main',
    ]);
}

/**
 * GitHub answering for `octocat/orders-api`: its branch, its tree and its manifests.
 *
 * @return array<string, mixed>
 */
function gitHubRepository(): array
{
    return [
        'https://api.github.com/repos/octocat/orders-api/branches/main' => Http::response([
            'commit' => ['sha' => 'commit-1', 'commit' => ['tree' => ['sha' => 'tree-1']]],
        ]),
        'https://api.github.com/repos/octocat/orders-api/git/trees/tree-1*' => Http::response(['tree' => [
            ['path' => 'package.json', 'type' => 'blob'],
            ['path' => 'src', 'type' => 'tree'],
            ['path' => 'src/server.js', 'type' => 'blob'],
            ['path' => 'node_modules/express/index.js', 'type' => 'blob'],
            ['path' => 'README.md', 'type' => 'blob'],
        ]]),
        'https://api.github.com/repos/octocat/orders-api/contents/package.json*' => Http::response('{"name":"orders-api"}'),
        'https://api.github.com/repos/octocat/orders-api/contents/src/server.js*' => Http::response('require("express")().listen(3000)'),
    ];
}

test('asking for an analysis queues it', function (): void {
    Queue::fake();
    $project = connectedProject();
    Passport::actingAs($project->user);
    Http::fake(['https://api.github.com/repos/octocat/orders-api' => Http::response(['full_name' => 'octocat/orders-api'])]);

    $this->postJson(route('projects.analysis.store', $project))->assertAccepted();

    Queue::assertPushed(AnalyzeProjectRepository::class, fn (AnalyzeProjectRepository $job): bool => $job->project->is($project));
});

test('an analysis is not queued for a repository the account cannot reach', function (): void {
    Queue::fake();
    $project = connectedProject();
    Passport::actingAs($project->user);
    Http::fake(['https://api.github.com/repos/octocat/orders-api' => Http::response(['message' => 'Not Found'], 404)]);

    $this->postJson(route('projects.analysis.store', $project))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('repositorio.nombreCompleto');

    Queue::assertNothingPushed();
});

test('an analysis without a connected account asks to connect', function (): void {
    Queue::fake();
    Http::fake();
    $project = Project::factory()->create();
    Passport::actingAs($project->user);

    $this->postJson(route('projects.analysis.store', $project))
        ->assertConflict()
        ->assertJsonPath('reconectar', true);

    Queue::assertNothingPushed();
});

test('nobody analyses the project of somebody else', function (): void {
    Queue::fake();
    Http::fake();
    $project = connectedProject();
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.analysis.store', $project))->assertNotFound();
    $this->getJson(route('projects.analysis.show', $project))->assertNotFound();
    $this->postJson(route('projects.deployment-branch.store', $project))->assertNotFound();

    Queue::assertNothingPushed();
});

test('the analysis reads the repository, stores the deployment in the bucket and waits for approval', function (): void {
    $project = connectedProject();
    $store = fakeProjectFileStore();
    $model = fakeGenerativeModel(analysisJson());
    Http::fake(gitHubRepository());

    (new AnalyzeProjectRepository($project))->handle(app(ProjectAnalyzer::class));

    $sent = json_decode((string) $model->prompt, true);
    $prefix = "{$project->user_id}/orders-api";
    $stored = json_decode($store->objects["{$prefix}/analisis/analisis.json"], true);

    expect($sent['repository'])->toBe('octocat/orders-api')
        ->and($sent['files'])->toBe(['package.json', 'src/server.js', 'node_modules/express/index.js', 'README.md'])
        ->and($sent['target']['project_id'])->toBe('dpl-1-orders-api')
        ->and($sent['target']['organization_id'])->toBe('274265040353')
        ->and($sent['target']['region'])->toBe('us-central1')
        ->and($sent['target']['limits'])->toMatchArray(['cpu' => '1', 'memory_mb' => 512, 'max_instances' => 2, 'port' => 8080, 'public' => true])
        // Key files only, the root first; a dependency's index.js is not the application's.
        ->and($sent['manifests'])->toBe(['package.json' => '{"name":"orders-api"}', 'src/server.js' => 'require("express")().listen(3000)'])
        ->and(array_column($stored['components'], 'runs_as', 'name'))->toBe(['api' => 'service', 'migraciones' => 'job', 'tests' => 'none'])
        ->and($store->objects["{$prefix}/despliegue/Dockerfile"])->toStartWith('FROM node:22-alpine')
        ->and($store->objects["{$prefix}/despliegue/deployer/deploy.sh"])->toBe('#!/bin/sh')
        ->and($stored['files'])->toContain('Dockerfile', 'deployer/deploy.sh')
        ->and($stored['branch'])->toBe('main')
        ->and($project->fresh()->status)->toBe(ProjectStatus::PendingApproval);
});

test('an analysis that fails leaves the project failed', function (): void {
    $project = connectedProject();

    (new AnalyzeProjectRepository($project))->failed(new RuntimeException('boom'));

    expect($project->fresh()->status)->toBe(ProjectStatus::Failed);
});

test('an analysis whose worker died says so instead of repeating the wording of the queue', function (): void {
    $project = connectedProject();

    (new AnalyzeProjectRepository($project))->failed(
        new MaxAttemptsExceededException('App\\Jobs\\AnalyzeProjectRepository has been attempted too many times.'),
    );

    expect($project->fresh()->failure_reason)
        ->toContain('stopped before it finished')
        ->not->toContain('attempted too many times');
});

test('no worker is stopped, and no second one is started, while an analysis is within its time', function (): void {
    $analysis = (new AnalyzeProjectRepository(Project::factory()->make()))->timeout;
    $worker = config('horizon.defaults.supervisor-1.timeout');

    expect($analysis)->toBeLessThan($worker)
        ->and($worker)->toBeLessThan(config('queue.connections.redis.retry_after'));
});

test('the stored analysis is served to its owner', function (): void {
    $project = connectedProject();
    fakeProjectFileStore();
    fakeGenerativeModel(analysisJson());
    Http::fake(gitHubRepository());
    Passport::actingAs($project->user);

    $this->getJson(route('projects.analysis.show', $project))->assertNotFound();

    app(ProjectAnalyzer::class)->analyze($project);

    $this->getJson(route('projects.analysis.show', $project))
        ->assertOk()
        ->assertJsonPath('stack.framework', 'Express')
        ->assertJsonPath('files.0', 'Dockerfile');
});

test('the deployment files are copied to a branch of the repository', function (): void {
    $project = connectedProject();
    fakeProjectFileStore();
    fakeGenerativeModel(analysisJson());
    Http::fake([
        ...gitHubRepository(),
        'https://api.github.com/repos/octocat/orders-api/git/trees' => Http::response(['sha' => 'tree-2'], 201),
        'https://api.github.com/repos/octocat/orders-api/git/commits' => Http::response(['sha' => 'commit-2'], 201),
        'https://api.github.com/repos/octocat/orders-api/git/refs' => Http::response([], 201),
    ]);
    app(ProjectAnalyzer::class)->analyze($project);
    Passport::actingAs($project->user);

    $this->postJson(route('projects.deployment-branch.store', $project))
        ->assertCreated()
        ->assertExactJson(['rama' => 'deployer/orders-api']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/git/trees')
        && $request['base_tree'] === 'tree-1'
        && in_array(['path' => 'deployer/deploy.sh', 'mode' => '100755', 'type' => 'blob', 'content' => '#!/bin/sh'], $request['tree'], true));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/git/commits')
        && $request['tree'] === 'tree-2'
        && $request['parents'] === ['commit-1']);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/git/refs')
        && $request['ref'] === 'refs/heads/deployer/orders-api'
        && $request['sha'] === 'commit-2');
});

test('publishing again moves the branch that already exists', function (): void {
    $project = connectedProject();
    fakeProjectFileStore();
    fakeGenerativeModel(analysisJson());
    Http::fake([
        ...gitHubRepository(),
        'https://api.github.com/repos/octocat/orders-api/git/trees' => Http::response(['sha' => 'tree-2'], 201),
        'https://api.github.com/repos/octocat/orders-api/git/commits' => Http::response(['sha' => 'commit-2'], 201),
        'https://api.github.com/repos/octocat/orders-api/git/refs' => Http::response(['message' => 'Reference already exists'], 422),
        'https://api.github.com/repos/octocat/orders-api/git/refs/heads/deployer/orders-api' => Http::response([]),
    ]);
    app(ProjectAnalyzer::class)->analyze($project);
    Passport::actingAs($project->user);

    $this->postJson(route('projects.deployment-branch.store', $project))->assertCreated();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request['sha'] === 'commit-2'
        && $request['force'] === true);
});

test('there is no branch to publish before the first analysis', function (): void {
    Http::fake();
    $project = connectedProject();
    fakeProjectFileStore();
    Passport::actingAs($project->user);

    $this->postJson(route('projects.deployment-branch.store', $project))->assertNotFound();

    Http::assertNothingSent();
});

test('a deployment file of the analysis is served to its owner, and nothing else from the bucket', function (): void {
    $project = connectedProject();
    fakeProjectFileStore();
    fakeGenerativeModel(analysisJson());
    Http::fake(gitHubRepository());
    app(ProjectAnalyzer::class)->analyze($project);
    Passport::actingAs($project->user);

    $this->getJson(route('projects.analysis.file', ['project' => $project, 'ruta' => 'deployer/deploy.sh']))
        ->assertOk()
        ->assertExactJson(['ruta' => 'deployer/deploy.sh', 'contenido' => '#!/bin/sh']);

    $this->getJson(route('projects.analysis.file', ['project' => $project, 'ruta' => 'deployer/otro.sh']))->assertNotFound();
    $this->getJson(route('projects.analysis.file', ['project' => $project, 'ruta' => '../analisis/analisis.json']))->assertNotFound();
});
