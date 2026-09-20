<?php
return [
    'default' => 'file',
    'channels' => [
        'file' => [
            'type'  => 'File',
            'path'  => app()->getRuntimePath() . 'log/',
            'level' => ['error', 'sql', 'warning'],
        ],
    ],
];
