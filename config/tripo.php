<?php

return [
    'key' => env('TRIPO_API_KEY'),
    'base_url' => 'https://openapi.tripo3d.ai/v3',
    'model' => env('TRIPO_MODEL', 'v3.1-20260211'),
    'models' => ['v3.1-20260211', 'v3.0-20250812', 'v2.5-20250123'],
    'queue' => env('TRIPO_QUEUE', 'tripo'),
    'timeout' => max(1, min(45, (int) env('TRIPO_TIMEOUT', 25))),
    'connect_timeout' => max(1, min(10, (int) env('TRIPO_CONNECT_TIMEOUT', 5))),
    'poll_interval' => (int) env('TRIPO_POLL_INTERVAL', 15),
    'deadline_minutes' => (int) env('TRIPO_DEADLINE_MINUTES', 30),
    'max_read_failures' => (int) env('TRIPO_MAX_READ_FAILURES', 5),
    'max_image_kb' => (int) env('TRIPO_MAX_IMAGE_KB', 10240),
    'max_model_bytes' => (int) env('TRIPO_MAX_MODEL_BYTES', 52428800),
    // Exact hosts only. Add a host only after verifying it with Tripo.
    'asset_hosts' => array_filter(explode(',', env('TRIPO_ASSET_HOSTS', 'cdn.tripo3d.ai'))),
    'retention_days' => (int) env('TRIPO_RETENTION_DAYS', 30),
];
