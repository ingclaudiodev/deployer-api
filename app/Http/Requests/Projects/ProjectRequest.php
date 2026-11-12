<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectBilling;
use App\Enums\ProjectKind;
use App\Enums\RepositoryProvider;
use App\Models\Project;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rules shared by creating and editing a project. The payload has the shape of
 * `ProjectResource`, so validation errors point at the same paths the client reads.
 *
 * Each kind of project has its own settings: a field that does not apply to the
 * kind has no rule, so it never reaches the model and stays null there.
 */
abstract class ProjectRequest extends FormRequest
{
    /** Request path => model column. */
    private const array COLUMNS = [
        'nombre' => 'name',
        'tipo' => 'kind',
        'cobro' => 'billing',
        'repositorio.proveedor' => 'repo_provider',
        'repositorio.nombreCompleto' => 'repo_full_name',
        'repositorio.rama' => 'repo_branch',
        'region' => 'region',
        'recursos.cpu' => 'cpu',
        'recursos.memoriaMb' => 'memory_mb',
        'recursos.instanciasMin' => 'min_instances',
        'recursos.instanciasMax' => 'max_instances',
        'recursos.concurrencia' => 'concurrency',
        'recursos.tiempoEsperaSegundos' => 'timeout_seconds',
        'recursos.puerto' => 'port',
        'tarea.tareas' => 'tasks',
        'tarea.reintentos' => 'max_retries',
        'tarea.diferida' => 'delayed',
        'accesoPublico' => 'public_access',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * What can change after creation, for the given kind of project.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function editableRules(string $presence, ?ProjectKind $kind): array
    {
        $rules = [
            'repositorio.rama' => [$presence, 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'recursos.cpu' => [$presence, 'string', Rule::in(config('projects.cpu'))],
            'recursos.memoriaMb' => [$presence, 'integer', Rule::in(config('projects.memory_mb')), $this->enoughMemoryForCpu()],
        ];

        $instances = [$presence, 'integer', 'max:'.config('projects.max_instances')];
        $port = [$presence, 'integer', 'min:1', 'max:65535'];

        return $rules + match ($kind) {
            ProjectKind::Service => [
                'cobro' => [$presence, Rule::enum(ProjectBilling::class)],
                'recursos.instanciasMin' => [$presence, 'integer', 'min:0', 'lte:recursos.instanciasMax'],
                'recursos.instanciasMax' => [...$instances, 'min:1'],
                'recursos.concurrencia' => [$presence, 'integer', 'min:1', 'max:'.config('projects.max_concurrency')],
                'recursos.tiempoEsperaSegundos' => [$presence, 'integer', 'min:1', 'max:'.config('projects.max_timeout_seconds')],
                'recursos.puerto' => $port,
                'accesoPublico' => [$presence, 'boolean'],
            ],
            ProjectKind::Job => [
                'tarea.tareas' => [$presence, 'integer', 'min:1', 'max:'.config('projects.max_tasks')],
                'tarea.reintentos' => [$presence, 'integer', 'min:0', 'max:'.config('projects.max_retries')],
                'tarea.diferida' => [$presence, 'boolean'],
                'recursos.tiempoEsperaSegundos' => [$presence, 'integer', 'min:1', 'max:'.$this->maxTaskTimeout()],
            ],
            // A pool runs exactly this many instances; 0 leaves it stopped.
            ProjectKind::WorkerPool => [
                'recursos.instanciasMax' => [...$instances, 'min:0'],
            ],
            ProjectKind::Instance => [
                'recursos.puerto' => $port,
                'accesoPublico' => [$presence, 'boolean'],
            ],
            null => [],
        };
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function identityRules(): array
    {
        return [
            'nombre' => [
                'required', 'string', 'regex:/^[a-z][a-z0-9-]{0,47}[a-z0-9]$|^[a-z]$/',
                Rule::unique(Project::class, 'name')->where('user_id', $this->user()?->getAuthIdentifier()),
            ],
            'tipo' => ['required', Rule::enum(ProjectKind::class)],
            'repositorio.proveedor' => ['required', Rule::enum(RepositoryProvider::class)],
            'repositorio.nombreCompleto' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)+$/'],
            'region' => ['required', 'string', Rule::in(array_keys((array) config('projects.regions')))],
        ];
    }

    /**
     * A delayed execution waits for spare capacity and has to finish sooner.
     */
    private function maxTaskTimeout(): int
    {
        return $this->boolean('tarea.diferida')
            ? (int) config('projects.max_delayed_timeout_seconds')
            : (int) config('projects.max_task_timeout_seconds');
    }

    /**
     * The platform refuses the larger CPU sizes with little memory.
     */
    private function enoughMemoryForCpu(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $cpu = $this->input('recursos.cpu');
            $minimum = config('projects.cpu_min_memory_mb')[$cpu] ?? 0;

            if (is_numeric($value) && (int) $value < $minimum) {
                $fail(trans('validation.min.numeric', ['attribute' => $attribute, 'min' => $minimum]));
            }
        };
    }

    /**
     * The validated payload as model attributes.
     *
     * @return array<string, mixed>
     */
    public function modelAttributes(): array
    {
        $validated = $this->validated();
        $attributes = [];

        foreach (self::COLUMNS as $path => $column) {
            if (data_get($validated, $path) !== null) {
                $attributes[$column] = data_get($validated, $path);
            }
        }

        return $attributes;
    }
}
