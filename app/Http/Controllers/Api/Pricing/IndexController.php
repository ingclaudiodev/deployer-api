<?php

namespace App\Http\Controllers\Api\Pricing;

use App\Http\Controllers\Controller;
use App\Models\PricingItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * The public price list: what Deployer charges in each region it offers. Only
 * sale prices leave the API, as integers the client scales itself; the cloud's
 * own prices and region names never do.
 */
class IndexController extends Controller
{
    /**
     * API names of the catalog, in the order the price list shows them.
     */
    private const array MODELS = [
        'service_request' => 'servicio_solicitudes',
        'service_instance' => 'servicio_instancias',
        'job' => 'tareas',
        'deferred_job' => 'tareas_diferidas',
        'worker_pool' => 'trabajadores',
        'instance' => 'instancias',
        'ephemeral_disk' => 'disco_efimero',
        'build' => 'compilacion',
        'image_storage' => 'almacenamiento_imagen',
        'egress' => 'salida_datos',
    ];

    private const array RESOURCES = [
        'cpu' => 'cpu',
        'memory' => 'memoria',
        'request' => 'solicitudes',
        'disk' => 'disco',
        'gpu_l4' => 'gpu_l4',
        'gpu_l4_zonal' => 'gpu_l4_zonal',
        'gpu_rtx_pro_6000' => 'gpu_rtx_pro_6000',
        'gpu_rtx_pro_6000_zonal' => 'gpu_rtx_pro_6000_zonal',
        'build_1cpu_4gb' => 'maquina_1cpu_4gb',
        'build_2cpu_8gb' => 'maquina_2cpu_8gb',
        'build_8cpu_8gb' => 'maquina_8cpu_8gb',
        'build_32cpu_32gb' => 'maquina_32cpu_32gb',
        'build_ssd' => 'disco_compilacion',
        'image' => 'imagen',
        'egress_north_america' => 'destino_norteamerica',
        'egress_europe' => 'destino_europa',
        'egress_asia' => 'destino_asia',
        'egress_oceania_latam' => 'destino_oceania_latinoamerica',
        'egress_middle_east_africa' => 'destino_medio_oriente_africa',
        'egress_china' => 'destino_china',
    ];

    private const array UNITS = [
        'vcpu_second' => 'vcpu_segundo',
        'gib_second' => 'gib_segundo',
        'million_requests' => 'millon_solicitudes',
        'gib_hour' => 'gib_hora',
        'gpu_second' => 'gpu_segundo',
        'build_minute' => 'minuto',
        'gib' => 'gib',
    ];

    /** Region under which the catalog keeps the prices that are the same everywhere. */
    private const string GLOBAL_REGION = 'global';

    public function __invoke(): JsonResponse
    {
        /** @var array<string, string> $regions */
        $regions = config('projects.regions');

        $items = PricingItem::query()
            ->whereIn('region', [...array_values($regions), self::GLOBAL_REGION])
            ->orderBy('id')
            ->get()
            ->groupBy('region');

        return new JsonResponse([
            'moneda' => config('pricing.currency'),
            'escala' => config('pricing.scale'),
            'vigenteDesde' => PricingItem::query()->max('priced_on'),
            'generales' => $this->models($items->get(self::GLOBAL_REGION, collect()), general: true),
            'regiones' => collect($regions)
                ->map(fn (string $cloudRegion, string $id): array => [
                    'id' => $id,
                    'modelos' => $this->models($items->get($cloudRegion, collect())),
                ])
                ->values(),
        ]);
    }

    /**
     * @param  Collection<int, PricingItem>  $items
     * @return array<int, array{modelo: string, precios: array<int, array<string, mixed>>}>
     */
    private function models(Collection $items, bool $general = false): array
    {
        $byModel = $items->groupBy('billing_model');
        $models = [];

        foreach (self::MODELS as $model => $name) {
            if (! $byModel->has($model)) {
                continue;
            }

            $models[] = [
                'modelo' => $name,
                'precios' => $byModel->get($model, collect())
                    ->map(fn (PricingItem $item): array => $this->price($item, $general))
                    ->values()
                    ->all(),
            ];
        }

        return $models;
    }

    /**
     * A tiered price carries the volume it covers (`desde`, `hasta`); the others
     * say whether the resource is in use or idle. The build disk has a general
     * price and a price per region, so the general one gets its own name.
     *
     * @return array<string, mixed>
     */
    private function price(PricingItem $item, bool $general): array
    {
        /** @var array<string, array{0: int, 1: int|null}> $tiers */
        $tiers = config('pricing.volume_tiers');
        $tier = $tiers[$item->usage] ?? null;

        return [
            'recurso' => $general && $item->resource === 'build_ssd'
                ? 'disco_compilacion_general'
                : self::RESOURCES[$item->resource] ?? $item->resource,
            'uso' => match (true) {
                $tier !== null => 'tramo',
                $item->usage === 'idle' => 'inactivo',
                default => 'activo',
            },
            'unidad' => self::UNITS[$item->unit] ?? $item->unit,
            'precio' => $item->sale_price,
            ...($tier === null ? [] : ['desde' => $tier[0], 'hasta' => $tier[1]]),
        ];
    }
}
