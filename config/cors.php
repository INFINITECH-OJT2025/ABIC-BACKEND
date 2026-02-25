<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure CORS settings for your application. This explains
    | where cross-origin requests are allowed from, and what headers/methods
    | are permitted during both pre-flight and actual requests.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-token'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',      // Local Next.js development
        'http://127.0.0.1:3000',      // Alternative localhost
        // Add production domain here: 'https://yourdomain.com'
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];