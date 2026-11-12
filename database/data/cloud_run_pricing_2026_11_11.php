<?php

/*
| List prices of Cloud Run in USD, read from every region of the selector of
| https://cloud.google.com/run/pricing on 2026-11-11 (46 regions, 7 tables).
| Prices are kept as the page prints them, as strings, so no float ever touches
| them: the migration turns them into integers.
|
| The 46 regions repeat a handful of price sets, so the file is written by set:
|   - `compute`: the two regional tiers of CPU, memory and requests.
|   - `disk`: the ephemeral disk follows its own tier (us-west8 pays tier 1
|     compute and tier 2 disk).
|   - `gpu`: only some regions offer GPU, each at its own price.
| Committed-use discounts (CUD) and the free tier are Google's and are not resold,
| so they are not part of the catalog.
*/

$tier1 = [
    'africa-south1' => 'Johannesburg', 'asia-east1' => 'Taiwan', 'asia-northeast1' => 'Tokyo',
    'asia-northeast2' => 'Osaka', 'asia-south1' => 'Mumbai', 'asia-southeast3' => 'Bangkok',
    'asia-southeast4' => 'Kuala Lumpur', 'europe-north1' => 'Finland', 'europe-north2' => 'Stockholm',
    'europe-southwest1' => 'Madrid', 'europe-west1' => 'Belgium', 'europe-west4' => 'Netherlands',
    'europe-west8' => 'Milan', 'europe-west9' => 'Paris', 'me-west1' => 'Tel Aviv',
    'northamerica-south1' => 'Mexico', 'us-central1' => 'Iowa', 'us-east1' => 'South Carolina',
    'us-east4' => 'Northern Virginia', 'us-east5' => 'Columbus', 'us-east7' => 'Alabama',
    'us-south1' => 'Dallas', 'us-west1' => 'Oregon', 'us-west8' => 'Phoenix',
];

$tier2 = [
    'asia-east2' => 'Hong Kong', 'asia-northeast3' => 'Seoul', 'asia-south2' => 'Delhi',
    'asia-southeast1' => 'Singapore', 'asia-southeast2' => 'Jakarta', 'australia-southeast1' => 'Sydney',
    'australia-southeast2' => 'Melbourne', 'europe-central2' => 'Warsaw', 'europe-west10' => 'Berlin',
    'europe-west12' => 'Turin', 'europe-west2' => 'London', 'europe-west3' => 'Frankfurt',
    'europe-west6' => 'Zurich', 'me-central1' => 'Doha', 'me-central2' => 'Dammam',
    'northamerica-northeast1' => 'Montreal', 'northamerica-northeast2' => 'Toronto',
    'southamerica-east1' => 'Sao Paulo', 'southamerica-west1' => 'Santiago', 'us-west2' => 'Los Angeles',
    'us-west3' => 'Salt Lake City', 'us-west4' => 'Las Vegas',
];

return [
    'source' => 'https://cloud.google.com/run/pricing',
    'scraped_on' => '2026-11-11',

    // region => [city, tier]
    'regions' => [
        ...array_map(fn (string $city): array => [$city, 1], $tier1),
        ...array_map(fn (string $city): array => [$city, 2], $tier2),
    ],

    // tier => billing model => list of [resource, usage, unit, price]
    'compute' => [
        1 => [
            'service_instance' => [
                ['cpu', 'active', 'vcpu_second', '0.000018'],
                ['memory', 'active', 'gib_second', '0.000002'],
            ],
            'service_request' => [
                ['cpu', 'active', 'vcpu_second', '0.000024'],
                ['cpu', 'idle', 'vcpu_second', '0.0000025'],
                ['memory', 'active', 'gib_second', '0.0000025'],
                ['memory', 'idle', 'gib_second', '0.0000025'],
                ['request', 'active', 'million_requests', '0.40'],
            ],
            'job' => [
                ['cpu', 'active', 'vcpu_second', '0.000018'],
                ['memory', 'active', 'gib_second', '0.000002'],
            ],
            'deferred_job' => [
                ['cpu', 'active', 'vcpu_second', '0.0000126'],
                ['memory', 'active', 'gib_second', '0.0000014'],
            ],
            'worker_pool' => [
                ['cpu', 'active', 'vcpu_second', '0.000011244'],
                ['memory', 'active', 'gib_second', '0.000001235'],
            ],
            'instance' => [
                ['cpu', 'active', 'vcpu_second', '0.00000027'],
                ['memory', 'active', 'gib_second', '0.00000193'],
            ],
        ],
        2 => [
            'service_instance' => [
                ['cpu', 'active', 'vcpu_second', '0.0000216'],
                ['memory', 'active', 'gib_second', '0.0000024'],
            ],
            'service_request' => [
                ['cpu', 'active', 'vcpu_second', '0.0000336'],
                ['cpu', 'idle', 'vcpu_second', '0.0000035'],
                ['memory', 'active', 'gib_second', '0.0000035'],
                ['memory', 'idle', 'gib_second', '0.0000035'],
                ['request', 'active', 'million_requests', '0.40'],
            ],
            'job' => [
                ['cpu', 'active', 'vcpu_second', '0.0000216'],
                ['memory', 'active', 'gib_second', '0.0000024'],
            ],
            'deferred_job' => [
                ['cpu', 'active', 'vcpu_second', '0.00001512'],
                ['memory', 'active', 'gib_second', '0.00000168'],
            ],
            'worker_pool' => [
                ['cpu', 'active', 'vcpu_second', '0.000013493'],
                ['memory', 'active', 'gib_second', '0.000001482'],
            ],
            'instance' => [
                ['cpu', 'active', 'vcpu_second', '0.000000324'],
                ['memory', 'active', 'gib_second', '0.000002316'],
            ],
        ],
    ],

    // Ephemeral disk, per GiB-hour. Regions not listed pay the price of their compute tier.
    'disk' => [
        'price' => [1 => '0.000109589', 2 => '0.000131507'],
        'tier_overrides' => ['us-west8' => 2],
    ],

    // region => GPU => [price per second, price per second with zonal redundancy]
    'gpu' => [
        'asia-south1' => ['gpu_l4' => ['0.0001867', '0.0002909']],
        'europe-west1' => ['gpu_l4' => ['0.0001867', '0.0002909']],
        'us-east4' => ['gpu_l4' => ['0.0001867', '0.0002909']],
        'asia-south2' => ['gpu_rtx_pro_6000' => ['0.00043826', '0.00068296']],
        'asia-southeast1' => [
            'gpu_l4' => ['0.00022404', '0.00034908'],
            'gpu_rtx_pro_6000' => ['0.00043826', '0.00068296'],
        ],
        'europe-west4' => [
            'gpu_l4' => ['0.0001867', '0.0002909'],
            'gpu_rtx_pro_6000' => ['0.00036522', '0.00056913'],
        ],
        'us-central1' => [
            'gpu_l4' => ['0.0001867', '0.0002909'],
            'gpu_rtx_pro_6000' => ['0.00036522', '0.00056913'],
        ],
    ],

    // Which billing models sell GPU, and whether with zonal redundancy too.
    'gpu_models' => [
        'service_instance' => true,
        'worker_pool' => true,
        'job' => false,
        'deferred_job' => false,
    ],
];
