<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectBilling;
use App\Enums\ProjectKind;
use App\Enums\RepositoryProvider;
use App\Models\Project;
use App\Services\Projects\AcceleratorCatalog;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rules shared by creating and editing a project. The payload has the shape of
 * `ProjectResource`, so validation errors point at the same paths the client reads.
 *
 * Each kind of project has its own settings: a field that does not apply to the
 * kind has no rule, so it never reaches the model and stays null there.
 *
 * A GPU and an ephemeral disk are optional extras of the kinds that can carry
 * them. Both draw on quotas the whole account shares, so what one project may
 * take is capped here (`config/projects.php`).
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
     * The project being edited; `null` while one is being created.
     */
    protected function project(): ?Project
    {
        return null;
    }

    /**
     * What can change after creation, for the given kind of project.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function editableRules(string $presence, ?ProjectKind $kind): array
    {
        $gpu = $this->gpuType($kind);
        $shapes = $this->gpuShapes($gpu);

        $rules = [
            'repositorio.rama' => [$presence, 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            // With a GPU the instance takes one of the sizes that GPU comes with; without
            // one, those sizes are not on offer.
            'recursos.cpu' => [$presence, 'string', Rule::in($gpu === null ? config('projects.cpu') : array_column($shapes, 'cpu'))],
            'recursos.memoriaMb' => [
                $presence, 'integer',
                Rule::in($gpu === null ? config('projects.memory_mb') : array_column($shapes, 'memory_mb')),
                $gpu === null ? $this->enoughMemoryForCpu() : $this->sizeOfferedWithGpu($shapes),
            ],
        ];

        if ($kind !== null && $kind !== ProjectKind::Instance) {
            $rules += $this->gpuRules($kind) + $this->diskRules($kind);
        }

        // A GPU is a scarce, shared quota: fewer copies of the project may run at once.
        $copies = $gpu === null ? PHP_INT_MAX : (int) config('projects.gpu.max_instances');
        $instances = [$presence, 'integer', 'max:'.min((int) config('projects.max_instances'), $copies)];
        $port = [$presence, 'integer', 'min:1', 'max:65535'];

        return $rules + match ($kind) {
            ProjectKind::Service => [
                'cobro' => [$presence, Rule::enum(ProjectBilling::class), $this->billedPerInstanceWithGpu($gpu)],
                'recursos.instanciasMin' => [$presence, 'integer', 'min:0', 'lte:recursos.instanciasMax'],
                'recursos.instanciasMax' => [...$instances, 'min:1'],
                'recursos.concurrencia' => [$presence, 'integer', 'min:1', 'max:'.config('projects.max_concurrency')],
                'recursos.tiempoEsperaSegundos' => [$presence, 'integer', 'min:1', 'max:'.config('projects.max_timeout_seconds')],
                'recursos.puerto' => $port,
                'accesoPublico' => [$presence, 'boolean'],
            ],
            ProjectKind::Job => [
                'tarea.tareas' => [$presence, 'integer', 'min:1', 'max:'.min((int) config('projects.max_tasks'), $copies)],
                'tarea.reintentos' => [$presence, 'integer', 'min:0', 'max:'.config('projects.max_retries')],
                'tarea.diferida' => [$presence, 'boolean'],
                'recursos.tiempoEsperaSegundos' => [$presence, 'integer', 'min:1', 'max:'.$this->maxTaskTimeout($gpu)],
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
     * `gpu` is the whole choice: `null` means no GPU. Which GPU can be had, and
     * whether with zonal redundancy, depends on the region and the kind of project.
     *
     * @return array<string, array<int, mixed>>
     */
    private function gpuRules(ProjectKind $kind): array
    {
        return [
            'gpu' => ['nullable', 'array'],
            'gpu.tipo' => ['required_with:gpu', 'string', Rule::in(array_keys((array) config('projects.gpu.types'))), $this->gpuOffered($kind)],
            'gpu.redundanciaZonal' => ['sometimes', 'boolean', $this->zonalRedundancyOffered($kind)],
        ];
    }

    /**
     * `discoEfimero` is the whole choice: `null` means no disk. The path is where
     * the container sees it; the platform refuses to mount over its own trees.
     *
     * @return array<string, array<int, mixed>>
     */
    private function diskRules(ProjectKind $kind): array
    {
        /** @var array{min_gb: int, max_gb: int, max_total_gb: int} $limits */
        $limits = config('projects.ephemeral_disk');

        return [
            'discoEfimero' => ['nullable', 'array'],
            'discoEfimero.tamanoGb' => [
                'required_with:discoEfimero', 'integer', 'min:'.$limits['min_gb'], 'max:'.$limits['max_gb'],
                $this->diskWithinQuota($kind, $limits['max_total_gb']),
            ],
            'discoEfimero.ruta' => [
                'required_with:discoEfimero', 'string', 'max:255',
                'regex:/^(\/[A-Za-z0-9._-]+)+$/', 'not_regex:/^\/(dev|proc|sys)(\/|$)/', 'not_regex:/\/\.\.?(\/|$)/',
            ],
        ];
    }

    /**
     * The GPU the project would end up with, when it is one the platform knows and
     * the kind can carry one.
     */
    private function gpuType(?ProjectKind $kind): ?string
    {
        $type = $this->input('gpu.tipo');

        if ($kind === null || $kind === ProjectKind::Instance || ! is_string($type)) {
            return null;
        }

        return array_key_exists($type, (array) config('projects.gpu.types')) ? $type : null;
    }

    /**
     * @return array<int, array{cpu: string, memory_mb: int}>
     */
    private function gpuShapes(?string $gpu): array
    {
        return $gpu === null ? [] : (array) config("projects.gpu.types.{$gpu}.shapes");
    }

    /**
     * Public id of the region the project lives in; it cannot change once created.
     */
    private function regionId(): string
    {
        $project = $this->project();
        $region = $project === null ? $this->input('region') : $project->region;

        return is_string($region) ? $region : '';
    }

    private function gpuOffered(ProjectKind $kind): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kind): void {
            if (is_string($value) && $this->offer($kind, $value) === null) {
                $fail(__('This GPU is not available for this kind of project in this region.'));
            }
        };
    }

    private function zonalRedundancyOffered(ProjectKind $kind): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kind): void {
            $type = $this->input('gpu.tipo');
            $offer = is_string($type) ? $this->offer($kind, $type) : null;

            if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && $offer !== null && ! $offer['zonal']) {
                $fail(__('Zonal redundancy is not available for this GPU here.'));
            }
        };
    }

    /**
     * @return array{zonal: bool}|null
     */
    private function offer(ProjectKind $kind, string $type): ?array
    {
        return app(AcceleratorCatalog::class)->find($this->regionId(), $kind, $type, $this->boolean('tarea.diferida'));
    }

    /**
     * A GPU stays allocated between requests, so the platform only sells it to
     * services billed for the whole time their instances are up.
     */
    private function billedPerInstanceWithGpu(?string $gpu): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($gpu): void {
            if ($gpu !== null && $value !== ProjectBilling::Instance->value) {
                $fail(__('A service with a GPU must be billed per instance.'));
            }
        };
    }

    /**
     * CPU and memory are chosen together: the pair has to be one of the sizes.
     *
     * @param  array<int, array{cpu: string, memory_mb: int}>  $shapes
     */
    private function sizeOfferedWithGpu(array $shapes): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($shapes): void {
            $pair = ['cpu' => $this->cpu(), 'memory_mb' => is_numeric($value) ? (int) $value : 0];

            if (! in_array($pair, $shapes, true)) {
                $fail(__('This size is not offered with this GPU.'));
            }
        };
    }

    /**
     * The disk of every copy that can run at once comes out of one allowance.
     */
    private function diskWithinQuota(ProjectKind $kind, int $maxTotal): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kind, $maxTotal): void {
            $copies = $kind === ProjectKind::Job ? $this->input('tarea.tareas') : $this->input('recursos.instanciasMax');
            $copies = is_numeric($copies) ? max(1, (int) $copies) : 1;

            if (is_numeric($value) && (int) $value * $copies > $maxTotal) {
                $fail(__('The disk of every copy adds up to more than :max GiB.', ['max' => $maxTotal]));
            }
        };
    }

    /**
     * A delayed execution waits for spare capacity and has to finish sooner; so
     * does a task that holds a GPU.
     */
    private function maxTaskTimeout(?string $gpu): int
    {
        $limit = $this->boolean('tarea.diferida')
            ? (int) config('projects.max_delayed_timeout_seconds')
            : (int) config('projects.max_task_timeout_seconds');

        return $gpu === null ? $limit : min($limit, (int) config('projects.gpu.max_task_timeout_seconds'));
    }

    /**
     * The platform refuses the larger CPU sizes with little memory.
     */
    private function enoughMemoryForCpu(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $minimum = config('projects.cpu_min_memory_mb')[$this->cpu()] ?? 0;

            if (is_numeric($value) && (int) $value < $minimum) {
                $fail(trans('validation.min.numeric', ['attribute' => $attribute, 'min' => $minimum]));
            }
        };
    }

    /**
     * The CPU the memory is checked against. Empty when the request sends something
     * a CPU cannot be: the rule of the CPU reports that, and no size matches it.
     */
    private function cpu(): string
    {
        $cpu = $this->input('recursos.cpu');

        return is_string($cpu) || is_int($cpu) ? (string) $cpu : '';
    }

    /**
     * The validated payload as model attributes.
     *
     * The columns of a GPU and of a disk are only written when there is one to set
     * or one to take away: a project without them never touches those columns.
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

        if (array_key_exists('gpu', $validated)) {
            /** @var array{tipo: string, redundanciaZonal?: bool}|null $gpu */
            $gpu = $validated['gpu'];

            if ($gpu !== null) {
                $attributes['gpu'] = $gpu['tipo'];
                $attributes['gpu_zonal_redundancy'] = (bool) ($gpu['redundanciaZonal'] ?? false);
            } elseif ($this->project()?->gpu !== null) {
                $attributes['gpu'] = null;
                $attributes['gpu_zonal_redundancy'] = false;
            }
        }

        if (array_key_exists('discoEfimero', $validated)) {
            /** @var array{tamanoGb: int, ruta: string}|null $disk */
            $disk = $validated['discoEfimero'];

            if ($disk !== null) {
                $attributes['ephemeral_disk_gb'] = $disk['tamanoGb'];
                $attributes['ephemeral_disk_path'] = $disk['ruta'];
            } elseif ($this->project()?->ephemeral_disk_gb !== null) {
                $attributes['ephemeral_disk_gb'] = null;
                $attributes['ephemeral_disk_path'] = null;
            }
        }

        return $attributes;
    }
}
