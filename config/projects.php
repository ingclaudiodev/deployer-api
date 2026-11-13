<?php

$list = fn (string $key, string $default): array => array_values(array_filter(
    array_map('trim', explode(',', (string) env($key, $default))),
    fn (string $value): bool => $value !== '',
));

/** `cpu:memory_mb` pairs, the instance sizes a GPU comes with. */
$shapes = fn (string $key, string $default): array => array_map(function (string $pair): array {
    [$cpu, $memory] = array_pad(explode(':', $pair, 2), 2, '0');

    return ['cpu' => $cpu, 'memory_mb' => (int) $memory];
}, $list($key, $default));

$regions = [];

foreach ($list('PROJECT_REGIONS', 'norteamerica:us-central1,sudamerica:southamerica-west1,europa:europe-west1') as $pair) {
    [$id, $cloudRegion] = array_pad(explode(':', $pair, 2), 2, '');
    $regions[$id] = $cloudRegion;
}

return [

    /*
    | What a user may ask for when creating a project. The API serves these to the
    | client (`GET projects/options`) and validates against them, so the limits
    | live in one place.
    */

    /*
    | Where the projects of the users run: each one gets a cloud project of its own
    | inside the organization, so nothing a project deploys can reach another one
    | or Deployer itself. The billing account is only named here; it is not a secret,
    | but it never goes to the client.
    */
    'platform' => [
        'organization_id' => env('DEPLOYER_ORGANIZATION_ID'),
        'billing_account' => env('DEPLOYER_BILLING_ACCOUNT'),
        // Start of every cloud project id. Ids are global: keep it distinctive.
        'project_prefix' => env('DEPLOYER_PROJECT_PREFIX', 'dpl'),
    ],

    // Projects one account may hold.
    'max_per_user' => (int) env('PROJECT_MAX_PER_USER', 20),

    // Public id => cloud region. Only the id leaves the API: the provider's region
    // names are never shown to users (PRODUCT.md of the client, Brand Commitments).
    'regions' => $regions,
    'default_region' => env('PROJECT_DEFAULT_REGION', array_key_first($regions)),

    // vCPU per instance, and the least memory (MB) the platform accepts with each.
    'cpu' => $list('PROJECT_CPU_OPTIONS', '1,2,4'),
    'cpu_min_memory_mb' => ['4' => 2048, '6' => 4096, '8' => 4096],
    'memory_mb' => array_map('intval', $list('PROJECT_MEMORY_OPTIONS_MB', '512,1024,2048,4096')),

    'max_instances' => (int) env('PROJECT_MAX_INSTANCES', 10),

    // Jobs: tasks one execution may run, retries of a failed task and how long a task may
    // take. The platform allows 10,000 tasks, 10 retries and 168 hours; a delayed execution
    // (waits for spare capacity, at a lower price) must finish within 12 hours.
    'max_tasks' => (int) env('PROJECT_MAX_TASKS', 10000),
    'max_retries' => (int) env('PROJECT_MAX_RETRIES', 10),
    'max_task_timeout_seconds' => (int) env('PROJECT_MAX_TASK_TIMEOUT_SECONDS', 604800),
    'max_delayed_timeout_seconds' => (int) env('PROJECT_MAX_DELAYED_TIMEOUT_SECONDS', 43200),
    'max_concurrency' => (int) env('PROJECT_MAX_CONCURRENCY', 1000),
    'max_timeout_seconds' => (int) env('PROJECT_MAX_TIMEOUT_SECONDS', 3600),

    /*
    | GPU. One per instance, and only with the instance sizes the platform accepts
    | for it (4 vCPU and 16 GiB at least for an L4, with 8 and 32 recommended; 20 and
    | 80 for an RTX PRO 6000): those sizes are offered with the GPU and never without
    | it. Where each GPU exists, and whether it can have zonal redundancy, is not
    | configured here: it comes from the price catalog (AcceleratorCatalog).
    */
    'gpu' => [
        'types' => [
            'l4' => ['vram_gb' => 24, 'shapes' => $shapes('PROJECT_GPU_L4_SHAPES', '4:16384,8:32768')],
            'rtx_pro_6000' => ['vram_gb' => 96, 'shapes' => $shapes('PROJECT_GPU_RTX_PRO_6000_SHAPES', '20:81920')],
        ],
        // Copies of one project that may hold a GPU at the same time: instances of a
        // service or of a worker pool, tasks of a job. The account's GPU quota (3 per
        // region until it is raised) is shared by every project, so this stays small.
        'max_instances' => (int) env('PROJECT_GPU_MAX_INSTANCES', 1),
        // A job task that uses a GPU may run for 12 hours at most.
        'max_task_timeout_seconds' => (int) env('PROJECT_GPU_MAX_TASK_TIMEOUT_SECONDS', 43200),
    ],

    /*
    | Ephemeral disk: real disk an instance gets while it lives, mounted at a path,
    | so large temporary files do not use up its memory. It is erased when the
    | instance stops. The platform allows 1 to 100 GiB per volume, 10 GiB per
    | instance until the quota is raised, and 100 GiB per region for the whole
    | account: `max_total_gb` caps what one project may take of it (size x copies).
    */
    'ephemeral_disk' => [
        'min_gb' => (int) env('PROJECT_DISK_MIN_GB', 1),
        'max_gb' => (int) env('PROJECT_DISK_MAX_GB', 10),
        'max_total_gb' => (int) env('PROJECT_DISK_MAX_TOTAL_GB', 100),
        'default_gb' => (int) env('PROJECT_DISK_DEFAULT_GB', 10),
        'default_path' => env('PROJECT_DISK_DEFAULT_PATH', '/mnt/disco'),
    ],

    'defaults' => [
        'cpu' => env('PROJECT_DEFAULT_CPU', '1'),
        'memory_mb' => (int) env('PROJECT_DEFAULT_MEMORY_MB', 512),
        'min_instances' => (int) env('PROJECT_DEFAULT_MIN_INSTANCES', 0),
        'max_instances' => (int) env('PROJECT_DEFAULT_MAX_INSTANCES', 2),
        'concurrency' => (int) env('PROJECT_DEFAULT_CONCURRENCY', 80),
        'timeout_seconds' => (int) env('PROJECT_DEFAULT_TIMEOUT_SECONDS', 300),
        'port' => (int) env('PROJECT_DEFAULT_PORT', 8080),
        'tasks' => (int) env('PROJECT_DEFAULT_TASKS', 1),
        'max_retries' => (int) env('PROJECT_DEFAULT_MAX_RETRIES', 3),
        'task_timeout_seconds' => (int) env('PROJECT_DEFAULT_TASK_TIMEOUT_SECONDS', 600),
        'worker_instances' => (int) env('PROJECT_DEFAULT_WORKER_INSTANCES', 1),
    ],

];
