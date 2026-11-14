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
        ->and(array_column($analysis['files'], 'path'))->toContain('Dockerfile', 'deployer/services/orders-api.yaml')
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
    'file that climbs out of the repository' => [['files' => [['path' => 'deployer/../../.ssh/authorized_keys']]]],
    'no Dockerfile for a repository without one' => [['files' => [['path' => '.dockerignore']]]],
    'service without its manifest' => [['services' => [['name' => 'worker']]]],
])->throws(InvalidAnalysisException::class, 'expected structure');

test('it takes out the variables Cloud Run reserves and tells the reviewer', function (): void {
    fakeGenerativeModel(analysisJson([
        'files' => [6 => ['path' => 'deployer/env/common.env', 'content' => "NODE_ENV=production\nPORT=8080\nexport K_SERVICE=orders\nREPORT=1\n"]],
        'env_vars' => [2 => ['name' => 'PORT', 'description' => 'Puerto HTTP', 'secret' => false]],
    ]));

    $analysis = app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api']);

    expect(collect($analysis['files'])->firstWhere('path', 'deployer/env/common.env')['content'])->toBe("NODE_ENV=production\nREPORT=1\n")
        ->and(array_column($analysis['env_vars'], 'name'))->toBe(['DATABASE_URL', 'LOG_LEVEL'])
        ->and(last($analysis['warnings']))->toContain('PORT de deployer/env/common.env', 'K_SERVICE');
});

test('it leaves the warnings alone when nothing reserved was declared', function (): void {
    fakeGenerativeModel(analysisJson());

    $analysis = app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api']);

    expect($analysis['warnings'])->toBe(['BullMQ necesita un worker siempre encendido.']);
});

test('it rejects a manifest Cloud Run would refuse or serve closed', function (array $overrides): void {
    fakeGenerativeModel(analysisJson($overrides));

    app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api']);
})->with([
    'reserved variable written in the manifest' => [['files' => [4 => ['content' => "kind: Service\nmetadata:\n  annotations:\n    run.googleapis.com/invoker-iam-disabled: 'true'\nspec:\n  template:\n    spec:\n      containers:\n        - env:\n            - name: PORT\n              value: '8080'"]]]],
    'public service its manifest does not open' => [['files' => [4 => ['content' => 'kind: Service']]]],
])->throws(InvalidAnalysisException::class, 'expected structure');

test('it lets a failure of the model reach the caller', function (): void {
    fakeGenerativeModel(GenerativeModelException::incompleteAnswer('MAX_TOKENS'));

    app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api']);
})->throws(GenerativeModelException::class, 'MAX_TOKENS');

test('without a project the model refuses before calling Google', function (): void {
    config(['services.vertex_ai.project' => null]);

    app(GenerativeModel::class)->generateJson('system', 'prompt', ['type' => 'OBJECT']);
})->throws(GenerativeModelException::class, 'VERTEX_AI_PROJECT');

test('it rejects a process that does not come from the inventory of components', function (array $overrides): void {
    fakeGenerativeModel(analysisJson($overrides));

    app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api']);
})->with([
    'service of a component nobody listed' => [['services' => [['component' => 'fantasma']]]],
    'job run as a service' => [['services' => [1 => ['kind' => 'service']]]],
    'runtime component left without a process' => [['components' => [2 => ['runs_as' => 'service']]]],
])->throws(InvalidAnalysisException::class);

test('it rejects a deployment that does not aim at the cloud project the platform gave it', function (array $target): void {
    fakeGenerativeModel(analysisJson());

    app(CloudArchitectAgent::class)->analyze([
        'repository' => 'acme/orders-api',
        'target' => ['organization_id' => '1', 'kind' => 'servicio', 'limits' => [], ...$target],
    ]);
})->with([
    'another project' => [['project_id' => 'dpl-2-otro', 'region' => 'us-central1']],
    'another region' => [['project_id' => 'dpl-1-orders-api', 'region' => 'europe-west1']],
])->throws(InvalidAnalysisException::class, 'target');

test('it sends the target of the platform to the model and accepts a deployment that uses it', function (): void {
    $model = fakeGenerativeModel(analysisJson());
    $target = ['organization_id' => '274265040353', 'project_id' => 'dpl-1-orders-api', 'region' => 'us-central1', 'kind' => 'servicio', 'limits' => ['cpu' => '1']];

    app(CloudArchitectAgent::class)->analyze(['repository' => 'acme/orders-api', 'target' => $target]);

    expect(json_decode((string) $model->prompt, true)['target'])->toBe($target);
});

