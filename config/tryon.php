<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Foot tracking
    |--------------------------------------------------------------------------
    |
    | Tracking runs entirely on the shopper's device with WebAR.rocks.hand
    | (MIT). NN_FOOT_31 detects feet wearing shoes, socks or bare feet.
    | NN_BAREFOOT_3 is 15x smaller but only reliable on bare feet.
    |
    */

    'neural_network' => env('TRYON_NEURAL_NETWORK', 'vendor/webar-rocks/neural-nets/NN_FOOT_31.json'),

    // Detection sensitivity in [0, 1]. Lower values find feet faster but
    // accept more false positives.
    'detection_threshold' => (float) env('TRYON_DETECTION_THRESHOLD', 0.8),

    'max_feet' => 2,

    /*
    | Vertical field of view (degrees) assumed for the smallest video dimension.
    | The 3D pose is solved against it, so a value far from the real camera tilts
    | and mis-scales the shoe. 33 is the WebAR.rocks default and a good start;
    | tune on real phones if shoes sit at an angle.
    */
    'camera_fov' => (float) env('TRYON_CAMERA_FOV', 33),

    // Canvas resolution is capped to keep low-end phones responsive.
    'max_device_pixel_ratio' => (float) env('TRYON_MAX_DPR', 1.5),

    'occluder_model' => 'models/occluder.glb',

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    |
    | Bump TRYON_ASSET_VERSION whenever vendor files, sample models or the
    | neural network change; it is appended to URLs that are cached for a year.
    |
    */

    'asset_version' => env('TRYON_ASSET_VERSION', '2'),

    'max_model_upload_kb' => (int) env('TRYON_MAX_MODEL_UPLOAD_KB', 25 * 1024),

    'max_poster_upload_kb' => (int) env('TRYON_MAX_POSTER_UPLOAD_KB', 2 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Embedding
    |--------------------------------------------------------------------------
    |
    | Origins allowed to embed /try-on pages in an iframe, e.g. an existing
    | Shopify or WooCommerce store. The embedding page must also add
    | allow="camera" to the iframe. Comma separated, "self" is always allowed.
    |
    */

    'embed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRYON_EMBED_ORIGINS', '')),
    ))),

];
