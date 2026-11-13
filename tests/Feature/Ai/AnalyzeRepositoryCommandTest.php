<?php

use App\Exceptions\Ai\GenerativeModelException;
use Illuminate\Support\Facades\File;

test('it prints the analysis of the simulated repository', function (): void {
    $model = fakeGenerativeModel(analysisJson());

    $this->artisan('architect:analyze')
        ->expectsOutputToContain('"framework": "Express"')
        ->assertSuccessful();

    expect($model->prompt)->toContain('acme/orders-api');
});

test('it analyses the repository described in a context file', function (): void {
    $model = fakeGenerativeModel(analysisJson());
    $path = storage_path('framework/testing/repository-context.json');
    File::put($path, (string) json_encode(['repository' => 'acme/billing', 'files' => ['go.mod']]));

    $this->artisan('architect:analyze', ['--context' => $path])->assertSuccessful();

    expect($model->prompt)->toContain('acme/billing')->toContain('go.mod');

    File::delete($path);
});

test('it writes the generated files under the output directory', function (): void {
    fakeGenerativeModel(analysisJson());
    $directory = storage_path('framework/testing/architect-output');

    $this->artisan('architect:analyze', ['--output' => $directory])->assertSuccessful();

    expect(File::get("{$directory}/Dockerfile"))->toStartWith('FROM node:22-alpine')
        ->and(File::exists("{$directory}/deployer/services/orders-api.yaml"))->toBeTrue()
        ->and(File::exists("{$directory}/deployer/jobs/migrate.yaml"))->toBeTrue();

    File::deleteDirectory($directory);
});

test('it refuses a context file without a repository', function (): void {
    $model = fakeGenerativeModel(analysisJson());

    $this->artisan('architect:analyze', ['--context' => '/nonexistent/context.json'])
        ->expectsOutputToContain('must be a JSON file')
        ->assertExitCode(2);

    expect($model->prompt)->toBeNull();
});

test('it reports a failure of the model instead of crashing', function (): void {
    fakeGenerativeModel(GenerativeModelException::notConfigured('VERTEX_AI_PROJECT'));

    $this->artisan('architect:analyze')
        ->expectsOutputToContain('VERTEX_AI_PROJECT')
        ->assertFailed();
});
