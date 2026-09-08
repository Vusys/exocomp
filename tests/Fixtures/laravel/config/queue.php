<?php

/*
 * Built in a variable and returned at the end, so a comment block can sit above each section.
 * Plenty of real config files are written this way, and handling only `return [ … ]` reports
 * every key in one of them as undefined — a confident answer about a file sitting right there.
 */
$queue = [
    'default' => env('QUEUE_CONNECTION', 'sync'),

    'connections' => [
        'redis' => [
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ],
];

return $queue;
