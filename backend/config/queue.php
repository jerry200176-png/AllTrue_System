<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | AllTrue intentionally processes its lightweight notifications in-process:
    | the deployed environment has no queue worker.  Laravel 8 supplied this
    | default internally; Laravel 9 requires the connection to be configured
    | by the application.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'sync'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

    ],

    'failed' => [
        'driver' => 'null',
    ],

];
