<?php

namespace App\Http\Resources;

use App\Enums\ProjectKind;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project as the client reads and writes it: the `Proyecto` type of its
 * `proyecto.types.ts`. The cloud region behind `region` is never serialized.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->name,
            'tipo' => $this->kind->value,
            'cobro' => $this->billing?->value,
            'repositorio' => [
                'proveedor' => $this->repo_provider->value,
                'nombreCompleto' => $this->repo_full_name,
                'rama' => $this->repo_branch,
            ],
            'region' => $this->region,
            'recursos' => [
                'cpu' => $this->cpu,
                'memoriaMb' => $this->memory_mb,
                'instanciasMin' => $this->min_instances,
                'instanciasMax' => $this->max_instances,
                'concurrencia' => $this->concurrency,
                'tiempoEsperaSegundos' => $this->timeout_seconds,
                'puerto' => $this->port,
            ],
            'tarea' => $this->kind === ProjectKind::Job ? [
                'tareas' => $this->tasks,
                'reintentos' => $this->max_retries,
                'diferida' => $this->delayed,
            ] : null,
            'accesoPublico' => $this->public_access,
            'estado' => $this->status->value,
            'url' => $this->url,
            'creadoEn' => $this->created_at->toIso8601String(),
            'actualizadoEn' => $this->updated_at->toIso8601String(),
        ];
    }
}
