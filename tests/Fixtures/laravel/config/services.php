<?php

return [
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET', 'sk_test'),
    ],

    'timeout' => 30,

    // A value that is not a literal at all. Printing something plausible here would be a lie
    // on every machine the application runs on.
    'retries' => (int) (getenv('RETRIES') ?: 3),
];
