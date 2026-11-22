<?php

/*
 * Only the testing options differ from the package defaults (the other keys are merged in from
 * vendor/inertiajs/inertia-laravel/config/inertia.php).
 *
 * Page components live in resources/js/pages, lowercase as in the Laravel Vue starter kit. The
 * package default, js/Pages, only resolved on case-insensitive filesystems (Docker Desktop on
 * Windows), so assertInertia() reported every page as missing on Linux CI.
 */
return [

    'testing' => [

        'ensure_pages_exist' => true,

        'page_paths' => [

            resource_path('js/pages'),

        ],

        'page_extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
