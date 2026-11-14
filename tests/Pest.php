<?php

use App\Enums\ProjectFileAction;
use App\Models\Project;
use App\Services\Ai\Contracts\GenerativeModel;
use App\Services\Ai\GeneratedJson;
use App\Services\Ai\GeneratedText;
use App\Services\Projects\Storage\Contracts\ProjectFileStore;
use App\Services\Projects\Storage\ProjectFilePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Passport can only mint personal access tokens when a personal access client exists,
 * and the in-memory test database starts without one.
 */
function createPersonalAccessClient(): Client
{
    return Client::factory()->asPersonalAccessTokenClient()->create();
}

/**
 * Stands in for Vertex AI: answers with the given text and keeps what it was sent.
 */
function fakeGenerativeModel(string|Throwable $answer): GenerativeModel
{
    $model = new class($answer) implements GenerativeModel
    {
        public ?string $prompt = null;

        /** @var array<int, array<int, array{role: string, text: string}>> */
        public array $conversations = [];

        public function __construct(private readonly string|Throwable $answer) {}

        public function generateJson(string $systemInstruction, string $prompt, array $schema): GeneratedJson
        {
            $this->prompt = $prompt;

            if ($this->answer instanceof Throwable) {
                throw $this->answer;
            }

            return new GeneratedJson($this->answer, 'gemini-test', inputTokens: 900, outputTokens: 350);
        }

        public function converse(string $systemInstruction, array $messages): GeneratedText
        {
            $this->prompt = $systemInstruction;
            $this->conversations[] = $messages;

            if ($this->answer instanceof Throwable) {
                throw $this->answer;
            }

            return new GeneratedText($this->answer, 'gemini-test', inputTokens: 40, outputTokens: 12);
        }
    };

    app()->instance(GenerativeModel::class, $model);

    return $model;
}

/**
 * Stands in for the bucket: keeps the objects in memory under the names the real
 * store would give them (`$objects`), so a test can read what was written.
 */
function fakeProjectFileStore(): ProjectFileStore
{
    $store = new class implements ProjectFileStore
    {
        /** @var array<string, string> */
        public array $objects = [];

        public function put(Project $project, ProjectFileAction $action, string $path, string $contents): string
        {
            $this->objects[ProjectFilePath::object($project, $action, $path)] = $contents;

            return '1';
        }

        public function get(Project $project, ProjectFileAction $action, string $path): ?string
        {
            return $this->objects[ProjectFilePath::object($project, $action, $path)] ?? null;
        }

        public function paths(Project $project, ProjectFileAction $action): array
        {
            $prefix = ProjectFilePath::prefix($project, $action);
            $paths = [];

            foreach (array_keys($this->objects) as $name) {
                if (str_starts_with($name, $prefix)) {
                    $paths[] = substr($name, strlen($prefix));
                }
            }

            return $paths;
        }
    };

    app()->instance(ProjectFileStore::class, $store);

    return $store;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function analysisJson(array $overrides = []): string
{
    return (string) json_encode(array_replace_recursive([
        'summary' => 'API Express con Postgres y Redis.',
        'stack' => ['language' => 'JavaScript', 'framework' => 'Express', 'runtime_version' => '22', 'package_manager' => 'npm'],
        'container' => ['has_dockerfile' => false, 'port' => 3000, 'start_command' => 'node src/server.js'],
        'components' => [
            ['name' => 'api', 'path' => '.', 'role' => 'API HTTP', 'runs_as' => 'service', 'evidence' => ['package.json', 'src/server.js']],
            ['name' => 'migraciones', 'path' => 'prisma', 'role' => 'Migraciones de la base', 'runs_as' => 'job', 'evidence' => ['prisma/schema.prisma']],
            ['name' => 'tests', 'path' => 'test', 'role' => 'Pruebas', 'runs_as' => 'none', 'evidence' => ['test/api.test.js']],
        ],
        'services' => [
            ['name' => 'orders-api', 'component' => 'api', 'kind' => 'service', 'purpose' => 'API HTTP', 'command' => 'node src/server.js', 'public' => true, 'cpu' => '1', 'memory' => '512Mi', 'min_instances' => 0, 'max_instances' => 3, 'concurrency' => 80, 'timeout_seconds' => 60, 'cpu_always_allocated' => false],
            ['name' => 'migrate', 'component' => 'migraciones', 'kind' => 'job', 'purpose' => 'Migraciones', 'command' => 'npx prisma migrate deploy', 'public' => false, 'cpu' => '1', 'memory' => '512Mi', 'min_instances' => 0, 'max_instances' => 1, 'concurrency' => 1, 'timeout_seconds' => 600, 'cpu_always_allocated' => false],
        ],
        'files' => [
            ['path' => 'Dockerfile', 'content' => "FROM node:22-alpine\nUSER node\nCMD [\"node\", \"src/server.js\"]"],
            ['path' => 'deployer/config.sh', 'content' => "PROJECT=\${PROJECT:-dpl-1-orders-api}\nREGION=\${REGION:-us-central1}"],
            ['path' => 'deployer/setup.sh', 'content' => '#!/bin/sh'],
            ['path' => 'deployer/deploy.sh', 'content' => '#!/bin/sh'],
            ['path' => 'deployer/services/orders-api.yaml', 'content' => "kind: Service\nmetadata:\n  annotations:\n    run.googleapis.com/invoker-iam-disabled: 'true'"],
            ['path' => 'deployer/jobs/migrate.yaml', 'content' => 'kind: Job'],
        ],
        'env_vars' => [
            ['name' => 'DATABASE_URL', 'description' => 'Conexión a Postgres', 'secret' => true],
            ['name' => 'LOG_LEVEL', 'description' => 'Nivel de registro', 'secret' => false],
        ],
        'backing_services' => [['type' => 'postgres', 'reason' => 'Prisma']],
        'warnings' => ['BullMQ necesita un worker siempre encendido.'],
        'confidence' => 'medium',
    ], $overrides));
}
