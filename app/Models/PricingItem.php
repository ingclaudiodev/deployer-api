<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One line of the price catalog: a resource in a region under a billing model.
 * Prices are integers of 10^-scale USD (`config/pricing.php`). The catalog is
 * written by its migration and read-only for the application.
 *
 * @property int $id
 * @property string $region Cloud region. Internal: users only ever see the public id of `config/projects.php`.
 * @property string $region_name
 * @property int $region_tier
 * @property string $billing_model
 * @property string $resource
 * @property string $usage `active`, or `idle` for instances kept warm without traffic.
 * @property string $unit
 * @property int $cloud_price What the provider charges.
 * @property int $sale_price What Deployer charges: the cloud price plus the margin.
 * @property int $margin_percent
 * @property Carbon $priced_on
 */
#[Hidden(['cloud_price', 'margin_percent', 'region', 'region_name', 'region_tier'])]
class PricingItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'region_tier' => 'integer',
            'cloud_price' => 'integer',
            'sale_price' => 'integer',
            'margin_percent' => 'integer',
            'priced_on' => 'date',
        ];
    }
}
