<?php

$list = fn (string $key, string $default): array => array_values(array_filter(
    array_map('trim', explode(',', (string) env($key, $default))),
    fn (string $value): bool => $value !== '',
));

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
