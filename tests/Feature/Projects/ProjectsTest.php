<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function projectPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'nombre' => 'orders-api',
        'tipo' => 'servicio',
        'cobro' => 'solicitudes',
        'repositorio' => ['proveedor' => 'github', 'nombreCompleto' => 'acme/orders-api', 'rama' => 'main'],
        'region' => 'norteamerica',
        'recursos' => [
            'cpu' => '1',
            'memoriaMb' => 512,
            'instanciasMin' => 0,
            'instanciasMax' => 2,
            'concurrencia' => 80,
            'tiempoEsperaSegundos' => 300,
            'puerto' => 8080,
        ],
        'accesoPublico' => true,
    ], $overrides);
}

test('projects require a signed-in user', function (string $method, string $uri): void {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    'list' => ['GET', '/api/projects'],
    'options' => ['GET', '/api/projects/options'],
    'create' => ['POST', '/api/projects'],
    'show' => ['GET', '/api/projects/1'],
    'update' => ['PATCH', '/api/projects/1'],
    'delete' => ['DELETE', '/api/projects/1'],
]);

test('the list holds only the projects of the user, most recently changed first', function (): void {
    $user = User::factory()->create();
    $old = Project::factory()->for($user)->create(['updated_at' => now()->subDay()]);
    $recent = Project::factory()->for($user)->live()->create();
    Project::factory()->create();
    Passport::actingAs($user);

    $this->getJson(route('projects.index'))
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.id', $recent->id)
        ->assertJsonPath('0.estado', 'en_vivo')
        ->assertJsonPath('1.id', $old->id);
});

test('a project is created not deployed, in the shape the client reads', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = $this->postJson(route('projects.store'), projectPayload())->assertCreated();

    $project = $user->projects()->sole();
    $response->assertExactJson([
        ...projectPayload(),
        'tarea' => null,
        'gpu' => null,
        'discoEfimero' => null,
        'id' => $project->id,
        'estado' => 'sin_despliegue',
        'url' => null,
        'creadoEn' => $project->created_at->toIso8601String(),
        'actualizadoEn' => $project->updated_at->toIso8601String(),
    ]);
});

test('the client cannot set the status or the url of a project', function (): void {
    Passport::actingAs($user = User::factory()->create());

    $this->postJson(route('projects.store'), projectPayload(['estado' => 'en_vivo', 'url' => 'https://evil.test']))
        ->assertCreated();

    expect($user->projects()->sole())
        ->status->toBe(ProjectStatus::NotDeployed)
        ->url->toBeNull();
});

test('a project is rejected when it breaks a limit', function (array $overrides, string $field): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), projectPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Project::query()->count())->toBe(0);
})->with([
    'name with upper case' => [['nombre' => 'Orders'], 'nombre'],
    'name ending in a hyphen' => [['nombre' => 'orders-'], 'nombre'],
    'unknown provider' => [['repositorio' => ['proveedor' => 'bitbucket']], 'repositorio.proveedor'],
    'repository without owner' => [['repositorio' => ['nombreCompleto' => 'orders-api']], 'repositorio.nombreCompleto'],
    'unknown region' => [['region' => 'us-central1'], 'region'],
    'cpu not offered' => [['recursos' => ['cpu' => '64']], 'recursos.cpu'],
    'cpu that is not text' => [['recursos' => ['cpu' => ['4']]], 'recursos.cpu'],
    'memory not offered' => [['recursos' => ['memoriaMb' => 123]], 'recursos.memoriaMb'],
    'too little memory for the cpu' => [['recursos' => ['cpu' => '4', 'memoriaMb' => 512]], 'recursos.memoriaMb'],
    'more instances than allowed' => [['recursos' => ['instanciasMax' => 11]], 'recursos.instanciasMax'],
    'minimum above maximum' => [['recursos' => ['instanciasMin' => 3, 'instanciasMax' => 2]], 'recursos.instanciasMin'],
    'port out of range' => [['recursos' => ['puerto' => 70000]], 'recursos.puerto'],
    'unknown kind' => [['tipo' => 'funcion'], 'tipo'],
    'unknown billing' => [['cobro' => 'gratis'], 'cobro'],
]);

test('a name can be reused by another user but not twice by the same one', function (): void {
    Project::factory()->create(['name' => 'orders-api']);
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), projectPayload())->assertCreated();
    $this->postJson(route('projects.store'), projectPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nombre');
});

test('an account cannot hold more projects than the limit', function (): void {
    config(['projects.max_per_user' => 1]);
    $user = User::factory()->create();
    Project::factory()->for($user)->create();
    Passport::actingAs($user);

    $this->postJson(route('projects.store'), projectPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nombre');
});

test('the options are the limits the validation enforces', function (): void {
    config(['projects.max_instances' => 7, 'projects.regions' => ['sur' => 'southamerica-west1']]);
    Passport::actingAs(User::factory()->create());

    $response = $this->getJson(route('projects.options'))
        ->assertOk()
        ->assertJsonPath('instanciasMax', 7)
        ->assertJsonPath('regiones', ['sur'])
        ->assertJsonPath('porDefecto.recursos.puerto', 8080);

    expect($response->getContent())->not->toContain('southamerica-west1');
});

test('a project of somebody else does not exist for the user', function (string $method): void {
    $project = Project::factory()->create();
    Passport::actingAs(User::factory()->create());

    $this->json($method, "/api/projects/{$project->id}", ['accesoPublico' => false])->assertNotFound();

    expect($project->fresh()?->public_access)->toBeTrue();
})->with(['GET', 'PATCH', 'DELETE']);

test('the owner reads one project', function (): void {
    $project = Project::factory()->create(['name' => 'web']);
    Passport::actingAs($project->user);

    $this->getJson(route('projects.show', $project))
        ->assertOk()
        ->assertJsonPath('nombre', 'web')
        ->assertJsonPath('recursos.memoriaMb', 512);
});

test('the owner edits the limits one field at a time', function (): void {
    $project = Project::factory()->create();
    Passport::actingAs($project->user);

    $this->patchJson(route('projects.update', $project), ['recursos' => ['memoriaMb' => 1024]])
        ->assertOk()
        ->assertJsonPath('recursos.memoriaMb', 1024)
        ->assertJsonPath('recursos.cpu', '1');
});

test('an edit cannot rename the project or move it', function (): void {
    $project = Project::factory()->create(['name' => 'web']);
    Passport::actingAs($project->user);

    $this->patchJson(route('projects.update', $project), ['nombre' => 'other', 'region' => 'europa', 'accesoPublico' => false])
        ->assertOk()
        ->assertJsonPath('nombre', 'web')
        ->assertJsonPath('region', 'norteamerica')
        ->assertJsonPath('accesoPublico', false);
});

test('an edit of the memory alone is checked against the cpu the project has', function (): void {
    $project = Project::factory()->create(['cpu' => '4', 'memory_mb' => 2048]);
    Passport::actingAs($project->user);

    $this->patchJson(route('projects.update', $project), ['recursos' => ['memoriaMb' => 512]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recursos.memoriaMb');
});

test('an edit of the minimum alone is checked against the maximum the project has', function (): void {
    $project = Project::factory()->create(['min_instances' => 0, 'max_instances' => 2]);
    Passport::actingAs($project->user);

    $this->patchJson(route('projects.update', $project), ['recursos' => ['instanciasMin' => 1]])
        ->assertOk()
        ->assertJsonPath('recursos.instanciasMin', 1);
    $this->patchJson(route('projects.update', $project), ['recursos' => ['instanciasMin' => 3]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recursos.instanciasMin');
});

test('the owner deletes a project', function (): void {
    $project = Project::factory()->create();
    Passport::actingAs($project->user);

    $this->deleteJson(route('projects.destroy', $project))->assertNoContent();

    expect(Project::query()->count())->toBe(0);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function jobPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'nombre' => 'nightly-report',
        'tipo' => 'tarea',
        'repositorio' => ['proveedor' => 'github', 'nombreCompleto' => 'acme/reports', 'rama' => 'main'],
        'region' => 'norteamerica',
        'recursos' => ['cpu' => '1', 'memoriaMb' => 512, 'tiempoEsperaSegundos' => 3600],
        'tarea' => ['tareas' => 5, 'reintentos' => 2, 'diferida' => false],
    ], $overrides);
}

test('a job keeps its tasks and none of the settings of a service', function (): void {
    Passport::actingAs($user = User::factory()->create());

    $this->postJson(route('projects.store'), jobPayload([
        'cobro' => 'instancias', 'accesoPublico' => true, 'recursos' => ['puerto' => 8080, 'instanciasMax' => 4],
    ]))
        ->assertCreated()
        ->assertJsonPath('tipo', 'tarea')
        ->assertJsonPath('cobro', null)
        ->assertJsonPath('tarea', ['tareas' => 5, 'reintentos' => 2, 'diferida' => false])
        ->assertJsonPath('recursos.tiempoEsperaSegundos', 3600)
        ->assertJsonPath('recursos.puerto', null)
        ->assertJsonPath('recursos.instanciasMax', null)
        ->assertJsonPath('accesoPublico', null);

    expect($user->projects()->sole()->billing)->toBeNull();
});

test('a job is rejected when it breaks a limit of jobs', function (array $overrides, string $field): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), jobPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'no tasks' => [['tarea' => ['tareas' => 0]], 'tarea.tareas'],
    'more tasks than allowed' => [['tarea' => ['tareas' => 10001]], 'tarea.tareas'],
    'more retries than allowed' => [['tarea' => ['reintentos' => 11]], 'tarea.reintentos'],
    'a task longer than 168 hours' => [['recursos' => ['tiempoEsperaSegundos' => 604801]], 'recursos.tiempoEsperaSegundos'],
    'a delayed task longer than 12 hours' => [['tarea' => ['diferida' => true], 'recursos' => ['tiempoEsperaSegundos' => 43201]], 'recursos.tiempoEsperaSegundos'],
]);

test('a job task may run far longer than a request', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), jobPayload(['recursos' => ['tiempoEsperaSegundos' => 604800]]))
        ->assertCreated()
        ->assertJsonPath('recursos.tiempoEsperaSegundos', 604800);
});

test('a worker pool is a fixed number of instances that takes no requests', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), [
        'nombre' => 'mailer',
        'tipo' => 'trabajadores',
        'repositorio' => ['proveedor' => 'gitlab', 'nombreCompleto' => 'acme/mailer', 'rama' => 'main'],
        'region' => 'europa',
        'recursos' => ['cpu' => '1', 'memoriaMb' => 512, 'instanciasMax' => 2, 'puerto' => 8080],
        'accesoPublico' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('tipo', 'trabajadores')
        ->assertJsonPath('recursos.instanciasMax', 2)
        ->assertJsonPath('recursos.instanciasMin', null)
        ->assertJsonPath('recursos.puerto', null)
        ->assertJsonPath('accesoPublico', null)
        ->assertJsonPath('tarea', null);
});

test('a service may listen on any valid port', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), projectPayload(['recursos' => ['puerto' => 65535]]))
        ->assertCreated()
        ->assertJsonPath('recursos.puerto', 65535);
});

test('an instance listens on a port and has no scaling', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), [
        'nombre' => 'agent',
        'tipo' => 'instancia',
        'repositorio' => ['proveedor' => 'github', 'nombreCompleto' => 'acme/agent', 'rama' => 'main'],
        'region' => 'norteamerica',
        'recursos' => ['cpu' => '1', 'memoriaMb' => 1024, 'puerto' => 3000, 'instanciasMax' => 9],
        'accesoPublico' => false,
    ])
        ->assertCreated()
        ->assertJsonPath('tipo', 'instancia')
        ->assertJsonPath('recursos.puerto', 3000)
        ->assertJsonPath('recursos.instanciasMax', null)
        ->assertJsonPath('accesoPublico', false);
});

test('an edit cannot turn a project into another kind', function (): void {
    $project = Project::factory()->job()->create();
    Passport::actingAs($project->user);

    $this->patchJson(route('projects.update', $project), ['tipo' => 'servicio', 'tarea' => ['tareas' => 20], 'recursos' => ['puerto' => 80]])
        ->assertOk()
        ->assertJsonPath('tipo', 'tarea')
        ->assertJsonPath('tarea.tareas', 20)
        ->assertJsonPath('tarea.reintentos', 3)
        ->assertJsonPath('recursos.puerto', null);
});

test('a service can change how it is charged', function (): void {
    $project = Project::factory()->create();
    Passport::actingAs($project->user);

    $this->patchJson(route('projects.update', $project), ['cobro' => 'instancias'])
        ->assertOk()
        ->assertJsonPath('cobro', 'instancias');
});

test('the options name every kind and the limits of jobs', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->getJson(route('projects.options'))
        ->assertOk()
        ->assertJsonPath('tipos', ['servicio', 'tarea', 'trabajadores', 'instancia'])
        ->assertJsonPath('cobros', ['solicitudes', 'instancias'])
        ->assertJsonPath('tareasMax', 10000)
        ->assertJsonPath('tiempoEsperaTareaMaxSegundos', 604800)
        ->assertJsonPath('porDefecto.tarea.reintentos', 3);
});

/**
 * A service in North America with an L4: billed per instance and sized as the GPU requires.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gpuServicePayload(array $overrides = []): array
{
    return array_replace_recursive(projectPayload([
        'nombre' => 'chat-llm',
        'cobro' => 'instancias',
        'recursos' => ['cpu' => '4', 'memoriaMb' => 16384, 'instanciasMax' => 1],
        'gpu' => ['tipo' => 'l4'],
    ]), $overrides);
}

function gpuService(): Project
{
    return Project::factory()->create([
        'billing' => 'instancias', 'cpu' => '4', 'memory_mb' => 16384, 'max_instances' => 1, 'gpu' => 'l4',
    ]);
}

test('a service can carry a gpu where the catalog sells one', function (): void {
    Passport::actingAs($user = User::factory()->create());

    $this->postJson(route('projects.store'), gpuServicePayload())
        ->assertCreated()
        ->assertJsonPath('gpu', ['tipo' => 'l4', 'redundanciaZonal' => false])
        ->assertJsonPath('recursos.cpu', '4')
        ->assertJsonPath('recursos.memoriaMb', 16384);

    expect($user->projects()->sole())->gpu->toBe('l4')->gpu_zonal_redundancy->toBeFalse();
});

test('zonal redundancy is sold to services and worker pools, never to jobs', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), gpuServicePayload(['gpu' => ['redundanciaZonal' => true]]))
        ->assertCreated()
        ->assertJsonPath('gpu.redundanciaZonal', true);

    $this->postJson(route('projects.store'), jobPayload([
        'recursos' => ['cpu' => '4', 'memoriaMb' => 16384],
        'tarea' => ['tareas' => 1],
        'gpu' => ['tipo' => 'l4', 'redundanciaZonal' => true],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gpu.redundanciaZonal');
});

test('a gpu is rejected when the project cannot have it', function (array $overrides, string $field): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), gpuServicePayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Project::query()->count())->toBe(0);
})->with([
    'a region without gpus' => [['region' => 'sudamerica'], 'gpu.tipo'],
    'a gpu the region does not have' => [['region' => 'europa', 'gpu' => ['tipo' => 'rtx_pro_6000'], 'recursos' => ['cpu' => '20', 'memoriaMb' => 81920]], 'gpu.tipo'],
    'an unknown gpu' => [['gpu' => ['tipo' => 'a100']], 'gpu.tipo'],
    'billing per request' => [['cobro' => 'solicitudes'], 'cobro'],
    'less cpu than the gpu needs' => [['recursos' => ['cpu' => '2']], 'recursos.cpu'],
    'cpu that is not text' => [['recursos' => ['cpu' => ['4']]], 'recursos.cpu'],
    'less memory than the gpu needs' => [['recursos' => ['memoriaMb' => 4096]], 'recursos.memoriaMb'],
    'cpu and memory of two different sizes' => [['recursos' => ['cpu' => '8', 'memoriaMb' => 16384]], 'recursos.memoriaMb'],
    'more instances than the gpu quota allows' => [['recursos' => ['instanciasMax' => 2]], 'recursos.instanciasMax'],
]);

test('the sizes of a gpu are not on offer without it', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), projectPayload(['recursos' => ['cpu' => '20', 'memoriaMb' => 81920]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recursos.cpu', 'recursos.memoriaMb']);
});

test('a job with a gpu runs one task at a time for twelve hours at most', function (array $overrides, string $field): void {
    Passport::actingAs(User::factory()->create());

    $payload = jobPayload([
        'recursos' => ['cpu' => '4', 'memoriaMb' => 16384, 'tiempoEsperaSegundos' => 43200],
        'tarea' => ['tareas' => 1],
        'gpu' => ['tipo' => 'l4'],
    ]);

    $this->postJson(route('projects.store'), $payload)->assertCreated()->assertJsonPath('gpu.tipo', 'l4');
    $this->postJson(route('projects.store'), array_replace_recursive($payload, ['nombre' => 'otro'], $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'two tasks' => [['tarea' => ['tareas' => 2]], 'tarea.tareas'],
    'a task longer than twelve hours' => [['recursos' => ['tiempoEsperaSegundos' => 43201]], 'recursos.tiempoEsperaSegundos'],
]);

test('a delayed job can carry a gpu as well', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), jobPayload([
        'recursos' => ['cpu' => '4', 'memoriaMb' => 16384],
        'tarea' => ['tareas' => 1, 'diferida' => true],
        'gpu' => ['tipo' => 'l4'],
    ]))
        ->assertCreated()
        ->assertJsonPath('tarea.diferida', true)
        ->assertJsonPath('gpu', ['tipo' => 'l4', 'redundanciaZonal' => false]);
});

test('an instance takes neither a gpu nor a disk', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), [
        'nombre' => 'agent',
        'tipo' => 'instancia',
        'repositorio' => ['proveedor' => 'github', 'nombreCompleto' => 'acme/agent', 'rama' => 'main'],
        'region' => 'norteamerica',
        'recursos' => ['cpu' => '1', 'memoriaMb' => 1024, 'puerto' => 3000],
        'accesoPublico' => false,
        'gpu' => ['tipo' => 'l4'],
        'discoEfimero' => ['tamanoGb' => 10, 'ruta' => '/mnt/disco'],
    ])
        ->assertCreated()
        ->assertJsonPath('gpu', null)
        ->assertJsonPath('discoEfimero', null);
});

test('an edit adds a gpu together with the size and the billing it needs', function (): void {
    $project = Project::factory()->create();
    Passport::actingAs($project->user);

    $this->patchJson(route('projects.update', $project), ['gpu' => ['tipo' => 'l4']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cobro', 'recursos.cpu', 'recursos.memoriaMb']);

    $this->patchJson(route('projects.update', $project), [
        'cobro' => 'instancias',
        'recursos' => ['cpu' => '8', 'memoriaMb' => 32768, 'instanciasMax' => 1],
        'gpu' => ['tipo' => 'l4'],
    ])
        ->assertOk()
        ->assertJsonPath('gpu.tipo', 'l4')
        ->assertJsonPath('recursos.memoriaMb', 32768);
});

test('an edit takes the gpu away only with a size that exists without it', function (): void {
    $project = gpuService();
    Passport::actingAs($project->user);

    $this->patchJson(route('projects.update', $project), ['gpu' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recursos.memoriaMb');

    $this->patchJson(route('projects.update', $project), ['gpu' => null, 'recursos' => ['cpu' => '2', 'memoriaMb' => 2048]])
        ->assertOk()
        ->assertJsonPath('gpu', null)
        ->assertJsonPath('recursos.cpu', '2');

    expect($project->fresh())->gpu->toBeNull()->gpu_zonal_redundancy->toBeFalse();
});

test('a service that has a gpu cannot go back to billing per request', function (): void {
    $project = gpuService();
    Passport::actingAs($project->user);

    $this->patchJson(route('projects.update', $project), ['cobro' => 'solicitudes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cobro');

    $this->patchJson(route('projects.update', $project), ['gpu' => ['redundanciaZonal' => true]])
        ->assertOk()
        ->assertJsonPath('gpu', ['tipo' => 'l4', 'redundanciaZonal' => true]);
});

test('a project can mount an ephemeral disk', function (): void {
    Passport::actingAs($user = User::factory()->create());

    $this->postJson(route('projects.store'), projectPayload(['discoEfimero' => ['tamanoGb' => 10, 'ruta' => '/mnt/disco']]))
        ->assertCreated()
        ->assertJsonPath('discoEfimero', ['tamanoGb' => 10, 'ruta' => '/mnt/disco']);

    expect($user->projects()->sole())->ephemeral_disk_gb->toBe(10)->ephemeral_disk_path->toBe('/mnt/disco');
});

test('an ephemeral disk is rejected when it breaks a limit', function (array $disk, string $field): void {
    config(['projects.ephemeral_disk.max_total_gb' => 15]);
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), projectPayload(['discoEfimero' => $disk]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'no size' => [['ruta' => '/mnt/disco'], 'discoEfimero.tamanoGb'],
    'smaller than the minimum' => [['tamanoGb' => 0, 'ruta' => '/mnt/disco'], 'discoEfimero.tamanoGb'],
    'larger than an instance may have' => [['tamanoGb' => 11, 'ruta' => '/mnt/disco'], 'discoEfimero.tamanoGb'],
    'more than the allowance once every instance has one' => [['tamanoGb' => 8, 'ruta' => '/mnt/disco'], 'discoEfimero.tamanoGb'],
    'no path' => [['tamanoGb' => 5], 'discoEfimero.ruta'],
    'a relative path' => [['tamanoGb' => 5, 'ruta' => 'mnt/disco'], 'discoEfimero.ruta'],
    'a path under a system tree' => [['tamanoGb' => 5, 'ruta' => '/proc/disco'], 'discoEfimero.ruta'],
    'a path that climbs' => [['tamanoGb' => 5, 'ruta' => '/mnt/../etc'], 'discoEfimero.ruta'],
]);

test('the disk of a job counts once per task', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->postJson(route('projects.store'), jobPayload(['tarea' => ['tareas' => 20], 'discoEfimero' => ['tamanoGb' => 10, 'ruta' => '/tmp/datos']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('discoEfimero.tamanoGb');

    $this->postJson(route('projects.store'), jobPayload(['tarea' => ['tareas' => 10], 'discoEfimero' => ['tamanoGb' => 10, 'ruta' => '/tmp/datos']]))
        ->assertCreated()
        ->assertJsonPath('discoEfimero.tamanoGb', 10);
});

test('an edit resizes the disk or takes it away', function (): void {
    $project = Project::factory()->create(['ephemeral_disk_gb' => 10, 'ephemeral_disk_path' => '/mnt/disco']);
    Passport::actingAs($project->user);

    $this->patchJson(route('projects.update', $project), ['discoEfimero' => ['tamanoGb' => 5]])
        ->assertOk()
        ->assertJsonPath('discoEfimero', ['tamanoGb' => 5, 'ruta' => '/mnt/disco']);

    $this->patchJson(route('projects.update', $project), ['discoEfimero' => null])
        ->assertOk()
        ->assertJsonPath('discoEfimero', null);

    expect($project->fresh())->ephemeral_disk_gb->toBeNull()->ephemeral_disk_path->toBeNull();
});

test('a project without a gpu or a disk never writes their columns', function (): void {
    $project = Project::factory()->create();
    Passport::actingAs($project->user);
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^(insert into|update) "projects"/', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $this->postJson(route('projects.store'), projectPayload(['gpu' => null, 'discoEfimero' => null]))->assertCreated();
    $this->patchJson(route('projects.update', $project), ['gpu' => null, 'discoEfimero' => null, 'accesoPublico' => false])->assertOk();

    expect($writes)->toHaveCount(2)
        ->and(implode(' ', $writes))->not->toContain('gpu')->not->toContain('ephemeral_disk');
});

test('the options say where each gpu can be had and how much disk', function (): void {
    Passport::actingAs(User::factory()->create());

    $this->getJson(route('projects.options'))
        ->assertOk()
        ->assertJsonPath('gpu.tipos.0', [
            'tipo' => 'l4',
            'memoriaGpuGb' => 24,
            'formas' => [['cpu' => '4', 'memoriaMb' => 16384], ['cpu' => '8', 'memoriaMb' => 32768]],
        ])
        ->assertJsonPath('gpu.tipos.1.formas', [['cpu' => '20', 'memoriaMb' => 81920]])
        ->assertJsonPath('gpu.disponibilidad.norteamerica.servicio', [
            ['tipo' => 'l4', 'redundanciaZonal' => true],
            ['tipo' => 'rtx_pro_6000', 'redundanciaZonal' => true],
        ])
        ->assertJsonPath('gpu.disponibilidad.norteamerica.tarea.0', ['tipo' => 'l4', 'redundanciaZonal' => false])
        ->assertJsonPath('gpu.disponibilidad.europa.trabajadores', [['tipo' => 'l4', 'redundanciaZonal' => true]])
        ->assertJsonMissingPath('gpu.disponibilidad.norteamerica.instancia')
        ->assertJsonPath('gpu.disponibilidad.sudamerica', [])
        ->assertJsonPath('gpu.instanciasMax', 1)
        ->assertJsonPath('discoEfimero', [
            'tipos' => ['servicio', 'tarea', 'trabajadores'],
            'tamanoMinGb' => 1,
            'tamanoMaxGb' => 10,
            'totalMaxGb' => 100,
            'porDefecto' => ['tamanoGb' => 10, 'ruta' => '/mnt/disco'],
        ]);
});
