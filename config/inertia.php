<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Page components live in `resources/js/Pages` (capitalized). The path is
    | declared explicitly so page-existence assertions resolve on
    | case-sensitive filesystems.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'svelte',

        ],

    ],

];
