<?php

namespace App\Http\Controllers\Api\Projects;

use App\Enums\ProjectBilling;
use App\Enums\ProjectKind;
use App\Http\Controllers\Controller;
use App\Services\Projects\AcceleratorCatalog;
use Illuminate\Http\JsonResponse;

/**
 * What the creation form may offer: the same limits the requests validate
 * against, so the client never hardcodes them.
 */
class OptionsController extends Controller
{
    public function __invoke(AcceleratorCatalog $accelerators): JsonResponse
    {
        /** @var array<string, array{vram_gb: int, shapes: array<int, array{cpu: string, memory_mb: int}>}> $gpuTypes */
        $gpuTypes = config('projects.gpu.types');
        /** @var array{min_gb: int, max_gb: int, max_total_gb: int, default_gb: int, default_path: string} $disk */
        $disk = config('projects.ephemeral_disk');

        /** @var array{cpu: string, memory_mb: int, min_instances: int, max_instances: int, concurrency: int, timeout_seconds: int, port: int, tasks: int, max_retries: int, task_timeout_seconds: int, worker_instances: int} $defaults */
        $defaults = config('projects.defaults');

        return new JsonResponse([
            'tipos' => array_column(ProjectKind::cases(), 'value'),
            'cobros' => array_column(ProjectBilling::cases(), 'value'),
            'regiones' => array_keys((array) config('projects.regions')),
            'cpu' => config('projects.cpu'),
            'memoriaMb' => config('projects.memory_mb'),
            'memoriaMinimaPorCpu' => (object) config('projects.cpu_min_memory_mb'),
            'instanciasMax' => config('projects.max_instances'),
            'concurrenciaMax' => config('projects.max_concurrency'),
            'tiempoEsperaMaxSegundos' => config('projects.max_timeout_seconds'),
            'tareasMax' => config('projects.max_tasks'),
            'reintentosMax' => config('projects.max_retries'),
            'tiempoEsperaTareaMaxSegundos' => config('projects.max_task_timeout_seconds'),
            'tiempoEsperaDiferidaMaxSegundos' => config('projects.max_delayed_timeout_seconds'),
            'gpu' => [
                'tipos' => array_map(fn (string $type, array $gpu): array => [
                    'tipo' => $type,
                    'memoriaGpuGb' => $gpu['vram_gb'],
                    'formas' => array_map(fn (array $shape): array => [
                        'cpu' => $shape['cpu'],
                        'memoriaMb' => $shape['memory_mb'],
                    ], $gpu['shapes']),
                ], array_keys($gpuTypes), $gpuTypes),
                'disponibilidad' => array_map(
                    fn (array $kinds): object => (object) array_map(
                        fn (array $gpus): array => array_map(
                            fn (string $type, bool $zonal): array => ['tipo' => $type, 'redundanciaZonal' => $zonal],
                            array_keys($gpus),
                            $gpus,
                        ),
                        $kinds,
                    ),
                    $accelerators->byRegion(),
                ),
                'instanciasMax' => config('projects.gpu.max_instances'),
                'tiempoEsperaTareaMaxSegundos' => config('projects.gpu.max_task_timeout_seconds'),
            ],
            'discoEfimero' => [
                'tipos' => array_column(array_filter(ProjectKind::cases(), fn (ProjectKind $kind): bool => $kind !== ProjectKind::Instance), 'value'),
                'tamanoMinGb' => $disk['min_gb'],
                'tamanoMaxGb' => $disk['max_gb'],
                'totalMaxGb' => $disk['max_total_gb'],
                'porDefecto' => ['tamanoGb' => $disk['default_gb'], 'ruta' => $disk['default_path']],
            ],
            'porDefecto' => [
                'region' => config('projects.default_region'),
                'cobro' => ProjectBilling::Request->value,
                'tarea' => [
                    'tareas' => $defaults['tasks'],
                    'reintentos' => $defaults['max_retries'],
                    'diferida' => false,
                    'tiempoEsperaSegundos' => $defaults['task_timeout_seconds'],
                ],
                'instanciasTrabajadores' => $defaults['worker_instances'],
                'recursos' => [
                    'cpu' => $defaults['cpu'],
                    'memoriaMb' => $defaults['memory_mb'],
                    'instanciasMin' => $defaults['min_instances'],
                    'instanciasMax' => $defaults['max_instances'],
                    'concurrencia' => $defaults['concurrency'],
                    'tiempoEsperaSegundos' => $defaults['timeout_seconds'],
                    'puerto' => $defaults['port'],
                ],
            ],
        ]);
    }
}
