<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;

/**
 * Name, kind, repository and region identify the project and cannot change once
 * it exists; everything else can, one field at a time.
 */
class UpdateProjectRequest extends ProjectRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return $this->editableRules('sometimes', $project instanceof Project ? $project->kind : null);
    }

    /**
     * A partial edit is validated as the project would end up: the settings it does
     * not mention keep their current value, so each rule compares against the truth
     * (the memory against the CPU it has, the minimum against the maximum it has).
     * Settings the kind does not use are null and have no rule, so they stay out.
     */
    protected function prepareForValidation(): void
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return;
        }

        $resources = $this->input('recursos');
        $job = $this->input('tarea');

        $this->merge([
            'recursos' => [
                ...array_filter([
                    'cpu' => $project->cpu,
                    'memoriaMb' => $project->memory_mb,
                    'instanciasMin' => $project->min_instances,
                    'instanciasMax' => $project->max_instances,
                    'concurrencia' => $project->concurrency,
                    'tiempoEsperaSegundos' => $project->timeout_seconds,
                    'puerto' => $project->port,
                ], fn (mixed $value): bool => $value !== null),
                ...(is_array($resources) ? $resources : []),
            ],
            'tarea' => [
                ...array_filter([
                    'tareas' => $project->tasks,
                    'reintentos' => $project->max_retries,
                    'diferida' => $project->delayed,
                ], fn (mixed $value): bool => $value !== null),
                ...(is_array($job) ? $job : []),
            ],
        ]);
    }
}
