<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectKind;
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
        return $this->editableRules('sometimes', $this->project()?->kind);
    }

    protected function project(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }

    /**
     * A partial edit is validated as the project would end up: the settings it does
     * not mention keep their current value, so each rule compares against the truth
     * (the memory against the CPU it has, the minimum against the maximum it has,
     * the billing against the GPU it has). Settings the kind does not use are null
     * and have no rule, so they stay out.
     */
    protected function prepareForValidation(): void
    {
        $project = $this->project();

        if ($project === null) {
            return;
        }

        $resources = $this->input('recursos');
        $job = $this->input('tarea');

        $merged = [
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
            'gpu' => $this->choice('gpu', $project->gpu === null ? null : [
                'tipo' => $project->gpu,
                'redundanciaZonal' => (bool) $project->gpu_zonal_redundancy,
            ]),
            'discoEfimero' => $this->choice('discoEfimero', $project->ephemeral_disk_gb === null ? null : [
                'tamanoGb' => $project->ephemeral_disk_gb,
                'ruta' => $project->ephemeral_disk_path,
            ]),
        ];

        if ($project->kind === ProjectKind::Service && ! $this->has('cobro')) {
            $merged['cobro'] = $project->billing?->value;
        }

        $this->merge($merged);
    }

    /**
     * An optional extra as the edit leaves it: untouched when the edit does not
     * mention it, removed when it sends `null`, and otherwise what the project has
     * with the fields the edit sends on top.
     *
     * @param  array<string, mixed>|null  $current
     */
    private function choice(string $key, ?array $current): mixed
    {
        if (! $this->has($key)) {
            return $current;
        }

        $sent = $this->input($key);

        return is_array($sent) && $current !== null ? [...$current, ...$sent] : $sent;
    }
}
