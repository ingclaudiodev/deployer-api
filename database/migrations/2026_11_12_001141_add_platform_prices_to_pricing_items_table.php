<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Billing models this migration adds to the catalog, and removes on the way back. */
    private const array MODELS = ['build', 'image_storage', 'egress'];

    /**
     * Adds to the catalog what a deployment uses around the service itself: build
     * minutes and build disk, image storage and Internet data transfer out. Same
     * rules as the rest of `pricing_items`: integers of 10^-scale USD, the cloud's
     * list price and Deployer's sale price side by side.
     *
     * Prices that do not depend on the region go under the region `global`.
     */
    public function up(): void
    {
        foreach (array_chunk($this->rows(), 200) as $rows) {
            DB::table('pricing_items')->insert($rows);
        }
    }

    public function down(): void
    {
        DB::table('pricing_items')->whereIn('billing_model', self::MODELS)->delete();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        /** @var array{scraped_on: string, global: array<int, array{0: string, 1: string, 2: string, 3: string}>, build: array<int, array{0: array<int, string>, 1: array<int, string>, 2: string}>, build_machines: array<int, string>, egress_destinations: array<int, string>, egress_tiers: array<int, string>, egress: array<int, array{0: array<int, string>, 1: array<int, array<int, string>>}>} $data */
        $data = require database_path('data/platform_pricing_2026_11_11.php');

        // Region names and tiers come from the catalog itself: only regions already priced get rows.
        $regions = DB::table('pricing_items')
            ->select('region', 'region_name', 'region_tier')
            ->distinct()
            ->get()
            ->keyBy('region');

        $items = [];

        foreach ($data['global'] as [$model, $resource, $unit, $price]) {
            $items[] = ['global', 'Global', 0, $model, $resource, 'active', $unit, $price];
        }

        foreach ($data['build'] as [$buildRegions, $machines, $ssd]) {
            foreach ($buildRegions as $region) {
                if (! $regions->has($region)) {
                    continue;
                }

                foreach ($machines as $index => $price) {
                    $items[] = [$region, $regions[$region]->region_name, $regions[$region]->region_tier, 'build', $data['build_machines'][$index], 'active', 'build_minute', $price];
                }

                $items[] = [$region, $regions[$region]->region_name, $regions[$region]->region_tier, 'build', 'build_ssd', 'active', 'gib_hour', $ssd];
            }
        }

        foreach ($data['egress'] as [$egressRegions, $destinations]) {
            foreach ($egressRegions as $region) {
                if (! $regions->has($region)) {
                    continue;
                }

                foreach ($destinations as $destination => $tiers) {
                    foreach ($tiers as $tier => $price) {
                        $items[] = [
                            $region, $regions[$region]->region_name, $regions[$region]->region_tier, 'egress',
                            'egress_'.$data['egress_destinations'][$destination], $data['egress_tiers'][$tier], 'gib', $price,
                        ];
                    }
                }
            }
        }

        $margin = (int) config('pricing.margin_percent');
        $scale = (int) config('pricing.scale');
        $now = now();

        return array_map(function (array $item) use ($margin, $scale, $now, $data): array {
            [$region, $name, $tier, $model, $resource, $usage, $unit, $price] = $item;
            $cloud = $this->toInteger($price, $scale);

            return [
                'region' => $region,
                'region_name' => $name,
                'region_tier' => $tier,
                'billing_model' => $model,
                'resource' => $resource,
                'usage' => $usage,
                'unit' => $unit,
                'cloud_price' => $cloud,
                'sale_price' => intdiv($cloud * (100 + $margin), 100),
                'margin_percent' => $margin,
                'priced_on' => $data['scraped_on'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $items);
    }

    /**
     * A decimal written as text ("0.006") as an integer of 10^-scale, without
     * ever going through a float.
     */
    private function toInteger(string $decimal, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("[{$decimal}] has more than {$scale} decimals.");
        }

        return (int) ($whole.str_pad($fraction, $scale, '0'));
    }
};
