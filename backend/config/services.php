<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'github' => [
        'token' => env('GITHUB_TOKEN'),
    ],

    'line' => [
        'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN'),
        'channel_secret'       => env('LINE_CHANNEL_SECRET'),
        'liff_id'              => env('LINE_LIFF_ID'),
    ],

];
