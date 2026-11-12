<?php

return [

    /*
    | What Deployer adds on top of the cloud's list price, in percent. It is applied
    | when the catalog is written (the migration that creates `pricing_items`), so
    | changing it needs a new migration that rewrites `sale_price`: a price users
    | already saw never moves because an environment variable did.
    */
    'margin_percent' => (int) env('PRICING_MARGIN_PERCENT', 30),

    /*
    | Prices are integers of 10^-scale of the currency: with 12, picodollars. The
    | smallest list price has 9 decimals and the margin adds more, so nothing is
    | ever rounded. The client divides by 10^scale to show them.
    */
    'currency' => 'USD',
    'scale' => 12,

    /*
    | Monthly volume, in the unit of the price, each tier of a tiered price covers:
    | [from, up to]. `null` means no upper limit. The API serves these limits with
    | every tiered price, so the client never has to know them.
    */
    'volume_tiers' => [
        'tier_1' => [0, 1024],
        'tier_2' => [1024, 10240],
        'tier_3' => [10240, null],
    ],

];
