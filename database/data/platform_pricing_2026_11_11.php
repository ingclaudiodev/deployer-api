<?php

/*
| List prices, in USD, of what a deployment uses around Cloud Run, read on
| 2026-11-11 from every region of the selectors of:
|   - https://cloud.google.com/build/pricing            (default pool: build minutes and extra SSD)
|   - https://cloud.google.com/artifact-registry/pricing (image storage)
|   - https://cloud.google.com/vpc/network-pricing       (Internet data transfer out, Premium tier)
| Prices are strings, as printed, so no float ever touches them. Free allowances
| (build minutes, first 0.5 GiB stored, first GiB sent) are the cloud's and are
| not resold, so the first paid price applies from the first unit.
|
| Not in this file: Cloud Build private pools (Deployer builds in the default
| pool) and transfers between Artifact Registry locations (image and service
| live in the same region, where the transfer is free).
*/

// Internet data transfer out per GiB, by monthly volume: [0–1,024 GiB, 1,024–10,240 GiB, over 10,240 GiB].
$low = ['0.12', '0.11', '0.085'];
$lowNear = ['0.12', '0.11', '0.08'];
$mid = ['0.15', '0.13', '0.11'];
$high = ['0.19', '0.18', '0.15'];
$china = ['0.23', '0.22', '0.20'];

// Destinations, in order: north_america, europe, asia, oceania_latam (Australia, Indonesia,
// Korea, South America, Saudi Arabia), middle_east_africa, china.
$egress = [
    'africa_middle_east' => [$mid, $mid, $mid, $high, $mid, $china],
    'asia' => [$low, $low, $low, $high, $mid, $china],
    'japan' => [['0.12', '0.12', '0.085'], ['0.12', '0.12', '0.085'], $low, $high, ['0.15', '0.15', '0.11'], $china],
    'korea' => [['0.19', '0.19', '0.15'], ['0.19', '0.19', '0.15'], $high, ['0.19', '0.19', '0.15'], ['0.19', '0.19', '0.15'], ['0.23', '0.23', '0.20']],
    'oceania_latam' => [$high, $high, $high, $high, $high, $china],
    'europe' => [$low, $lowNear, $low, $high, $mid, $china],
    'saudi_arabia' => [$high, $high, $mid, $high, $high, $china],
    'north_america' => [$lowNear, $low, $low, $high, $mid, $china],
    'salt_lake_city' => [$lowNear, $low, $lowNear, $high, $mid, $china],
];

return [
    'scraped_on' => '2026-11-11',

    // Prices that do not depend on the region.
    'global' => [
        // [billing model, resource, unit, price]
        ['build', 'build_2cpu_8gb', 'build_minute', '0.006'],
        ['build', 'build_ssd', 'gib_hour', '0.000232877'],
        ['image_storage', 'image', 'gib_hour', '0.000136986'],
    ],

    // Default pool, by region: [1 vCPU / 4 GB, 8 vCPU / 8 GB, 32 vCPU / 32 GB] per minute, extra SSD per GiB-hour.
    'build' => [
        [['africa-south1', 'europe-north1', 'europe-west1', 'me-west1', 'northamerica-northeast1', 'northamerica-northeast2'], ['0.0033', '0.01716', '0.06864'], '0.000256164'],
        [['asia-east1', 'europe-west8', 'europe-west9'], ['0.00348', '0.018096', '0.072384'], '0.000270137'],
        [['asia-east2', 'europe-west6'], ['0.0042', '0.02184', '0.08736'], '0.000326027'],
        [['asia-northeast1', 'asia-northeast2', 'asia-northeast3'], ['0.00384', '0.019968', '0.079872'], '0.000298082'],
        [['asia-south1'], ['0.00312', '0.016224', '0.064896'], '0.000242192'],
        [['asia-south2', 'us-west2', 'us-west3'], ['0.0036', '0.01872', '0.07488'], '0.000279452'],
        [['asia-southeast1'], ['0.00369', '0.019188', '0.076752'], '0.000286438'],
        [['asia-southeast2'], ['0.00402', '0.020904', '0.083616'], '0.000312055'],
        [['asia-southeast3', 'europe-north2', 'europe-west4'], ['0.00315', '0.01638', '0.06552'], '0.000244521'],
        [['australia-southeast1'], ['0.00375', '0.0195', '0.078'], '0.000291096'],
        [['australia-southeast2'], ['0.0039', '0.02028', '0.08112'], '0.00030274'],
        [['europe-central2', 'europe-west12'], ['0.00387', '0.020124', '0.080496'], '0.000300411'],
        [['europe-southwest1', 'europe-west3', 'us-south1'], ['0.00354', '0.018408', '0.073632'], '0.000274795'],
        [['europe-west10'], ['0.00462', '0.024024', '0.096096'], '0.00035863'],
        [['europe-west2'], ['0.00342', '0.017784', '0.071136'], '0.000265479'],
        [['me-central1'], ['0.00366', '0.019032', '0.076128'], '0.00028411'],
        [['me-central2'], ['0.0048', '0.02496', '0.09984'], '0.000372603'],
        [['northamerica-south1'], ['0.00327', '0.017004', '0.068016'], '0.000253836'],
        [['southamerica-east1'], ['0.00477', '0.024804', '0.099216'], '0.000370274'],
        [['southamerica-west1'], ['0.00429', '0.022308', '0.089232'], '0.000333014'],
        [['us-central1', 'us-east4', 'us-east5', 'us-west1', 'us-west8'], ['0.003', '0.0156', '0.0624'], '0.000232877'],
        [['us-east1', 'us-west4'], ['0.00339', '0.017628', '0.070512'], '0.000263151'],
    ],
    'build_machines' => ['build_1cpu_4gb', 'build_8cpu_8gb', 'build_32cpu_32gb'],

    'egress_destinations' => ['north_america', 'europe', 'asia', 'oceania_latam', 'middle_east_africa', 'china'],
    'egress_tiers' => ['tier_1', 'tier_2', 'tier_3'],
    'egress' => [
        [['africa-south1', 'me-central1', 'me-west1'], $egress['africa_middle_east']],
        [['asia-east1', 'asia-east2', 'asia-south1', 'asia-south2', 'asia-southeast1', 'asia-southeast3'], $egress['asia']],
        [['asia-northeast1', 'asia-northeast2'], $egress['japan']],
        [['asia-northeast3'], $egress['korea']],
        [['asia-southeast2', 'australia-southeast1', 'australia-southeast2', 'southamerica-east1', 'southamerica-west1'], $egress['oceania_latam']],
        [[
            'europe-central2', 'europe-north1', 'europe-north2', 'europe-southwest1', 'europe-west1', 'europe-west10', 'europe-west12',
            'europe-west2', 'europe-west3', 'europe-west4', 'europe-west6', 'europe-west8', 'europe-west9',
        ], $egress['europe']],
        [['me-central2'], $egress['saudi_arabia']],
        [[
            'northamerica-northeast1', 'northamerica-northeast2', 'northamerica-south1', 'us-central1', 'us-east1', 'us-east4',
            'us-east5', 'us-south1', 'us-west1', 'us-west2', 'us-west4', 'us-west8',
        ], $egress['north_america']],
        [['us-west3'], $egress['salt_lake_city']],
    ],
];
