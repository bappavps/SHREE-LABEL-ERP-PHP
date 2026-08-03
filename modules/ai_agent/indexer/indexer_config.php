<?php
return [
    'ignore_folders' => [
        'vendor',
        'node_modules',
        '.git',
        'tests',
        'logs',
        'cache',
        'tmp',
        'uploads',
        'venv',
        'python_service'
    ],
    'ignore_files' => [
        'package.json',
        'package-lock.json',
        'composer.json',
        'composer.lock',
        '.gitignore',
        '.env'
    ],
    'supported_extensions' => [
        'php',
        'sql',
        'md'
    ],
    'manifest' => [
        'parser_version' => '1.0',
        'index_schema_version' => 1
    ]
];
