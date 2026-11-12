<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The price catalog: what one unit of each resource costs in each region and
     * billing model. `cloud_price` is the provider's list price and `sale_price`
     * what Deployer charges (list price plus the margin). Both are integers of
     * 10^-scale USD (`config/pricing.php`), never decimals.
     *
     * The catalog is fixed data the schema needs, so it is written here and not by
     * a seeder (CLAUDE.md): a database that has the table has the prices.
     */
    public function up(): void
    {
        Schema::create('pricing_items', function (Blueprint $table): void {
            $table->id();
            $table->string('region', 32);
            $table->string('region_name', 64);
            $table->unsignedTinyInteger('region_tier');
            $table->string('billing_model', 32);
            $table->string('resource', 32);
            $table->string('usage', 16);
            $table->string('unit', 32);
            $table->unsignedBigInteger('cloud_price');
            $table->unsignedBigInteger('sale_price');
            $table->unsignedTinyInteger('margin_percent');
            $table->date('priced_on');
            $table->timestamps();

            $table->unique(['region', 'billing_model', 'resource', 'usage']);
        });

        foreach (array_chunk($this->catalog(), 200) as $rows) {
            DB::table('pricing_items')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_items');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalog(): array
    {
        /** @var array{scraped_on: string, regions: array<string, array{0: string, 1: int}>, compute: array<int, array<string, array<int, array{0: string, 1: string, 2: string, 3: string}>>>, disk: array{price: array<int, string>, tier_overrides: array<string, int>}, gpu: array<string, array<string, array{0: string, 1: string}>>, gpu_models: array<string, bool>} $data */
        $data = require database_path('data/cloud_run_pricing_2026_11_11.php');

        $margin = (int) config('pricing.margin_percent');
        $scale = (int) config('pricing.scale');
        $now = now();
        $rows = [];

        foreach ($data['regions'] as $region => [$name, $tier]) {
            $prices = [];

            foreach ($data['compute'][$tier] as $model => $items) {
                foreach ($items as [$resource, $usage, $unit, $price]) {
                    $prices[] = [$model, $resource, $usage, $unit, $price];
                }
            }

            $diskTier = $data['disk']['tier_overrides'][$region] ?? $tier;
            $prices[] = ['ephemeral_disk', 'disk', 'active', 'gib_hour', $data['disk']['price'][$diskTier]];

            foreach ($data['gpu'][$region] ?? [] as $gpu => [$single, $zonal]) {
                foreach ($data['gpu_models'] as $model => $sellsZonal) {
                    $prices[] = [$model, $gpu, 'active', 'gpu_second', $single];

                    if ($sellsZonal) {
                        $prices[] = [$model, "{$gpu}_zonal", 'active', 'gpu_second', $zonal];
                    }
                }
            }

            foreach ($prices as [$model, $resource, $usage, $unit, $price]) {
                $cloud = $this->toInteger($price, $scale);

                $rows[] = [
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
            }
        }

        return $rows;
    }

    /**
     * A decimal written as text ("0.000024") as an integer of 10^-scale, without
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
