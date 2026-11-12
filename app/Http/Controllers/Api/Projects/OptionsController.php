<?php

namespace App\Http\Controllers\Api\Projects;

use App\Enums\ProjectBilling;
use App\Enums\ProjectKind;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * What the creation form may offer: the same limits the requests validate
 * against, so the client never hardcodes them.
 */
class OptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
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
