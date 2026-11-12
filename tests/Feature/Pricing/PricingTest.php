<?php

use App\Models\PricingItem;

function pricingItem(string $region, string $model, string $resource, string $usage = 'active'): PricingItem
{
    return PricingItem::query()
        ->where(['region' => $region, 'billing_model' => $model, 'resource' => $resource, 'usage' => $usage])
        ->sole();
}

test('the catalog holds the list price of every region as integers, with the margin on top', function (string $region, string $model, string $resource, string $usage, int $cloud, int $sale): void {
    expect(pricingItem($region, $model, $resource, $usage))
        ->cloud_price->toBe($cloud)
        ->sale_price->toBe($sale)
        ->margin_percent->toBe(30);
})->with([
    'tier 1 cpu per request' => ['us-central1', 'service_request', 'cpu', 'active', 24_000_000, 31_200_000],
    'tier 1 idle cpu' => ['us-central1', 'service_request', 'cpu', 'idle', 2_500_000, 3_250_000],
    'tier 2 cpu per request' => ['southamerica-west1', 'service_request', 'cpu', 'active', 33_600_000, 43_680_000],
    'tier 2 memory per instance' => ['southamerica-west1', 'service_instance', 'memory', 'active', 2_400_000, 3_120_000],
    'requests cost the same everywhere' => ['southamerica-east1', 'service_request', 'request', 'active', 400_000_000_000, 520_000_000_000],
    'worker pool memory keeps its nine decimals' => ['europe-west1', 'worker_pool', 'memory', 'active', 1_235_000, 1_605_500],
    'instances' => ['us-east1', 'instance', 'cpu', 'active', 270_000, 351_000],
    'deferred jobs in tier 2' => ['europe-west3', 'deferred_job', 'cpu', 'active', 15_120_000, 19_656_000],
    'disk follows its own tier' => ['us-west8', 'ephemeral_disk', 'disk', 'active', 131_507_000, 170_959_100],
    'gpu with zonal redundancy' => ['us-central1', 'service_instance', 'gpu_rtx_pro_6000_zonal', 'active', 569_130_000, 739_869_000],
    'gpu priced by its region' => ['asia-southeast1', 'job', 'gpu_l4', 'active', 224_040_000, 291_252_000],
    'standard build machine, the same everywhere' => ['global', 'build', 'build_2cpu_8gb', 'active', 6_000_000_000, 7_800_000_000],
    'build machine priced by its region' => ['southamerica-west1', 'build', 'build_8cpu_8gb', 'active', 22_308_000_000, 29_000_400_000],
    'build disk priced by its region' => ['us-east1', 'build', 'build_ssd', 'active', 263_151_000, 342_096_300],
    'image storage' => ['global', 'image_storage', 'image', 'active', 136_986_000, 178_081_800],
    'data out, first tier' => ['us-central1', 'egress', 'egress_north_america', 'tier_1', 120_000_000_000, 156_000_000_000],
    'data out, last tier to the same continent' => ['europe-west1', 'egress', 'egress_europe', 'tier_3', 80_000_000_000, 104_000_000_000],
    'data out from south america' => ['southamerica-west1', 'egress', 'egress_north_america', 'tier_2', 180_000_000_000, 234_000_000_000],
    'data out to china' => ['asia-northeast3', 'egress', 'egress_china', 'tier_2', 230_000_000_000, 299_000_000_000],
]);

test('the catalog covers the 46 regions and only sells gpu where the cloud does', function (): void {
    expect(PricingItem::query()->where('region', '!=', 'global')->distinct()->count('region'))->toBe(46)
        ->and(PricingItem::query()->where('region', 'southamerica-west1')->whereNotIn('billing_model', ['build', 'egress'])->count())->toBe(16)
        ->and(PricingItem::query()->where('region', 'southamerica-west1')->where('billing_model', 'build')->count())->toBe(4)
        ->and(PricingItem::query()->where('region', 'southamerica-west1')->where('billing_model', 'egress')->count())->toBe(18)
        ->and(PricingItem::query()->where('region', 'global')->count())->toBe(3)
        ->and(PricingItem::query()->where('billing_model', 'egress')->distinct()->count('region'))->toBe(44)
        ->and(PricingItem::query()->where('region', 'southamerica-west1')->where('resource', 'like', 'gpu%')->exists())->toBeFalse()
        ->and(PricingItem::query()->where('region', 'us-central1')->where('resource', 'like', 'gpu%')->count())->toBe(12)
        ->and(PricingItem::query()->where('billing_model', 'job')->where('resource', 'like', '%zonal')->exists())->toBeFalse();
});

test('the price list is public and shows the sale price of the offered regions', function (): void {
    config(['projects.regions' => ['norteamerica' => 'us-central1', 'sudamerica' => 'southamerica-west1']]);

    $response = $this->getJson(route('pricing.index'))
        ->assertOk()
        ->assertJsonPath('moneda', 'USD')
        ->assertJsonPath('escala', 12)
        ->assertJsonPath('vigenteDesde', '2026-11-11')
        ->assertJsonCount(2, 'regiones')
        ->assertJsonPath('regiones.0.id', 'norteamerica')
        ->assertJsonPath('regiones.0.modelos.0.modelo', 'servicio_solicitudes')
        ->assertJsonPath('regiones.0.modelos.0.precios.0', [
            'recurso' => 'cpu', 'uso' => 'activo', 'unidad' => 'vcpu_segundo', 'precio' => 31_200_000,
        ])
        ->assertJsonPath('regiones.1.modelos.0.precios.0.precio', 43_680_000)
        ->assertJsonCount(9, 'regiones.1.modelos')
        ->assertJsonPath('regiones.1.modelos.7.modelo', 'compilacion')
        ->assertJsonPath('regiones.1.modelos.7.precios.0', [
            'recurso' => 'maquina_1cpu_4gb', 'uso' => 'activo', 'unidad' => 'minuto', 'precio' => 5_577_000_000,
        ])
        ->assertJsonPath('regiones.1.modelos.8.modelo', 'salida_datos')
        ->assertJsonPath('regiones.1.modelos.8.precios.1', [
            'recurso' => 'destino_norteamerica', 'uso' => 'tramo', 'unidad' => 'gib', 'precio' => 234_000_000_000, 'desde' => 1024, 'hasta' => 10240,
        ])
        ->assertJsonPath('regiones.1.modelos.8.precios.2.hasta', null)
        ->assertJsonPath('generales.0.modelo', 'compilacion')
        ->assertJsonPath('generales.0.precios.0.precio', 7_800_000_000)
        ->assertJsonPath('generales.0.precios.1.recurso', 'disco_compilacion_general')
        ->assertJsonPath('regiones.1.modelos.7.precios.3.recurso', 'disco_compilacion')
        ->assertJsonPath('generales.1.modelo', 'almacenamiento_imagen')
        ->assertJsonPath('generales.1.precios.0', [
            'recurso' => 'imagen', 'uso' => 'activo', 'unidad' => 'gib_hora', 'precio' => 178_081_800,
        ]);

    expect($response->getContent())
        ->not->toContain('us-central1')
        ->not->toContain('southamerica')
        ->not->toContain('24000000,')
        ->not->toContain('e2-')
        ->not->toContain('highcpu');
});
