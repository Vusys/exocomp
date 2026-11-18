<?php

return [
    'defaults' => [
        'guard' => 'web',
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Models\Customer::class),
        ],
    ],
];
