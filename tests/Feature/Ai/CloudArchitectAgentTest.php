<?php

use App\Exceptions\Ai\GenerativeModelException;
use App\Exceptions\Ai\InvalidAnalysisException;
use App\Services\Ai\Contracts\GenerativeModel;
use App\Services\CloudArchitectAgent;

test('it returns the analysis of the model together with what the call consumed', function (): void {
    fakeGenerativeModel(analysisJson());

    $analysis = app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api']);

    expect($analysis['stack']['framework'])->toBe('Express')
        ->and($analysis['container']['port'])->toBe(3000)
        ->and($analysis['services'][1]['kind'])->toBe('job')
        ->and(array_column($analysis['files'], 'path'))->toContain('Dockerfile', 'cloudrun/services/orders-api.yaml')
        ->and($analysis['env_vars'][0])->toBe(['name' => 'DATABASE_URL', 'description' => 'Conexión a Postgres', 'secret' => true])
        ->and($analysis['usage'])->toBe(['model' => 'gemini-test', 'input_tokens' => 900, 'output_tokens' => 350, 'thinking_tokens' => 0]);
});

test('it trims the repository before sending it to the model', function (): void {
    config([
        'services.cloud_architect.max_files' => 2,
        'services.cloud_architect.max_manifest_characters' => 10,
    ]);
    $model = fakeGenerativeModel(analysisJson());

    app(CloudArchitectAgent::class)->analyze([
        'repository' => 'acme/orders-api',
        'files' => ['a.js', 'b.js', 'c.js', 'd.js'],
        'manifests' => ['package-lock.json' => str_repeat('x', 500)],
    ]);

    $sent = json_decode((string) $model->prompt, true);

    expect($sent['files'])->toBe(['a.js', 'b.js'])
        ->and($sent['files_omitted'])->toBe(2)
        ->and($sent['manifests']['package-lock.json'])->toBe(str_repeat('x', 10)."\n[truncated]");
});

test('it drops keys the schema does not know', function (): void {
    fakeGenerativeModel(analysisJson(['deploy_now' => true]));

    $analysis = app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api']);

    expect($analysis)->not->toHaveKey('deploy_now');
});

test('it rejects an answer that is not JSON', function (): void {
    fakeGenerativeModel('Here is your analysis: {');

    app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api']);
})->throws(InvalidAnalysisException::class, 'valid JSON');

test('it rejects an analysis Cloud Run would not accept', function (array $overrides): void {
    fakeGenerativeModel(analysisJson($overrides));

    app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api']);
})->with([
    'port out of range' => [['container' => ['port' => 70000]]],
    'memory without unit' => [['services' => [['memory' => 'plenty']]]],
    'fewer max than min instances' => [['services' => [['min_instances' => 5, 'max_instances' => 2]]]],
    'unknown confidence' => [['confidence' => 'absolute']],
    'env var name that is not an identifier' => [['env_vars' => [['name' => 'FOO; rm -rf /']]]],
    'file outside the deployment folders' => [['files' => [['path' => 'src/server.js']]]],
    'file that climbs out of the repository' => [['files' => [['path' => 'cloudrun/../../.ssh/authorized_keys']]]],
    'no Dockerfile for a repository without one' => [['files' => [['path' => '.dockerignore']]]],
    'service without its manifest' => [['services' => [['name' => 'worker']]]],
])->throws(InvalidAnalysisException::class, 'expected structure');

test('it lets a failure of the model reach the caller', function (): void {
    fakeGenerativeModel(GenerativeModelException::incompleteAnswer('MAX_TOKENS'));

    app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api']);
})->throws(GenerativeModelException::class, 'MAX_TOKENS');

test('without a project the model refuses before calling Google', function (): void {
    config(['services.vertex_ai.project' => null]);

    app(GenerativeModel::class)->generateJson('system', 'prompt', ['type' => 'OBJECT']);
})->throws(GenerativeModelException::class, 'VERTEX_AI_PROJECT');
