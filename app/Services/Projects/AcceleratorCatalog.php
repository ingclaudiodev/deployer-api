<?php

namespace App\Services\Projects;

use App\Enums\ProjectKind;
use App\Models\PricingItem;

/**
 * Which GPUs a project can use, read from the price catalog: a GPU exists for a
 * kind of project in a region only where it has a price there, and it can have
 * zonal redundancy only where that has a price too. The form options and the
 * validation both ask here, so they can never disagree.
 */
class AcceleratorCatalog
{
    /** How the catalog names its GPU resources: `gpu_<type>` and `gpu_<type>_zonal`. */
    private const string PREFIX = 'gpu_';

    private const string ZONAL = '_zonal';

    /**
     * Billing model of the catalog under which each kind of project buys its GPU.
     * A service only gets one when billed per instance; an instance never does.
     */
    public function billingModel(ProjectKind $kind, bool $delayed = false): ?string
    {
        return match ($kind) {
            ProjectKind::Service => 'service_instance',
            ProjectKind::Job => $delayed ? 'deferred_job' : 'job',
            ProjectKind::WorkerPool => 'worker_pool',
            ProjectKind::Instance => null,
        };
    }

    /**
     * GPUs of every offered region, by public region id and kind of project:
     * GPU type => whether zonal redundancy is sold with it. A job is listed as it
     * runs right away; a delayed one is checked against its own prices when saved.
     *
     * @return array<string, array<string, array<string, bool>>>
     */
    public function byRegion(): array
    {
        /** @var array<string, string> $regions */
        $regions = config('projects.regions');

        $resources = PricingItem::query()
            ->whereIn('region', array_values($regions))
            ->where('resource', 'like', self::PREFIX.'%')
            ->get(['region', 'billing_model', 'resource'])
            ->groupBy('region');

        $offered = [];

        foreach ($regions as $id => $cloudRegion) {
            $byModel = $resources->get($cloudRegion, collect())->groupBy('billing_model');
            $offered[$id] = [];

            foreach (ProjectKind::cases() as $kind) {
                $model = $this->billingModel($kind);
                $names = $model === null ? [] : $byModel->get($model, collect())->pluck('resource')->all();
                $gpus = $this->gpus($names);

                if ($gpus !== []) {
                    $offered[$id][$kind->value] = $gpus;
                }
            }
        }

        return $offered;
    }

    /**
     * The GPU as offered to that kind of project in that region, or `null` when
     * it is not sold there.
     *
     * @return array{zonal: bool}|null
     */
    public function find(string $region, ProjectKind $kind, string $type, bool $delayed = false): ?array
    {
        $cloudRegion = config('projects.regions')[$region] ?? null;
        $model = $this->billingModel($kind, $delayed);

        if (! is_string($cloudRegion) || $model === null) {
            return null;
        }

        $names = PricingItem::query()
            ->where('region', $cloudRegion)
            ->where('billing_model', $model)
            ->whereIn('resource', [self::PREFIX.$type, self::PREFIX.$type.self::ZONAL])
            ->pluck('resource')
            ->all();

        $gpus = $this->gpus($names);

        return array_key_exists($type, $gpus) ? ['zonal' => $gpus[$type]] : null;
    }

    /**
     * @param  array<int, string>  $resources  Catalog resources of one region and billing model.
     * @return array<string, bool> GPU type => zonal redundancy sold.
     */
    private function gpus(array $resources): array
    {
        $gpus = [];

        foreach ($resources as $resource) {
            if (! str_ends_with($resource, self::ZONAL)) {
                $gpus[substr($resource, strlen(self::PREFIX))] = in_array($resource.self::ZONAL, $resources, true);
            }
        }

        return $gpus;
    }
}
