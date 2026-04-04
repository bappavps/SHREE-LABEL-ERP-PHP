<?php

return [
    'default_slug' => 'default',
    'tenants' => [
        'default' => [
            'label' => 'Shree Label Creation',
            'active' => true,
            'hosts' => ['localhost', '127.0.0.1'],
            'path_prefixes' => ['/calipot-erp/shree-label-php'],
            'settings_file' => 'data/app_settings.json',
            'erp_display_name' => '',
            'db' => [
                'local' => [
                    'DB_NAME' => 'shree_label_erp',
                ],
                'live' => [
                ],
            ],
        ],
        /*
        'ram' => [
            'label' => 'Ram Company',
            'active' => true,
            'hosts' => ['ram.localhost', 'ram.example.com'],
            'path_prefixes' => ['/ram'],
            'settings_file' => 'data/tenants/ram/app_settings.json',
            'erp_display_name' => 'e-Flexo for Ram',
            'db' => [
                'local' => [
                    'DB_NAME' => 'ram_erp',
                ],
                'live' => [
                    'DB_NAME' => 'ram_live_erp',
                    'DB_USER' => 'ram_live_user',
                    'DB_PASS' => 'change-me',
                ],
            ],
        ],
        */
    ],
];