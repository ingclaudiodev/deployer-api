<?php

use App\Enums\ProjectFileAction;
use App\Exceptions\Projects\ProjectStorageException;
use App\Models\Project;
use App\Services\Projects\CloudProject;
use App\Services\Projects\Storage\ProjectFilePath;

test('a file of a project lives under user id, project name and action', function (): void {
    $project = Project::factory()->create(['name' => 'orders-api']);

    expect(ProjectFilePath::object($project, ProjectFileAction::Deployment, 'deployer/deploy.sh'))
        ->toBe("{$project->user_id}/orders-api/despliegue/deployer/deploy.sh")
        ->and(ProjectFilePath::object($project, ProjectFileAction::Analysis, 'analisis.json'))
        ->toBe("{$project->user_id}/orders-api/analisis/analisis.json");
});

test('a path that could leave the folder of its action is refused', function (string $path): void {
    $project = Project::factory()->create();

    ProjectFilePath::object($project, ProjectFileAction::Deployment, $path);
})->with([
    'climbs' => ['../otro-proyecto/Dockerfile'],
    'climbs in the middle' => ['deployer/../../Dockerfile'],
    'absolute' => ['/etc/passwd'],
    'empty' => [''],
    'empty segment' => ['deployer//deploy.sh'],
])->throws(ProjectStorageException::class);

test('the cloud project of a project has an id the platform accepts, always the same', function (string $name): void {
    $project = Project::factory()->create(['name' => $name]);

    $id = CloudProject::idFor($project);

    expect($id)->toMatch('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/')
        ->toStartWith("dpl-{$project->user_id}-")
        ->and(CloudProject::idFor($project->fresh()))->toBe($id);
})->with(['short' => ['api'], 'long' => ['a-very-long-project-name-that-does-not-fit-in-an-id']]);

test('two long names that begin alike get different cloud projects', function (): void {
    $user = Project::factory()->create()->user;
    $one = Project::factory()->for($user)->create(['name' => 'a-very-long-project-name-number-one']);
    $two = Project::factory()->for($user)->create(['name' => 'a-very-long-project-name-number-two']);

    expect(CloudProject::idFor($one))->not->toBe(CloudProject::idFor($two));
});

