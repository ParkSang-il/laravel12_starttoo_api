<?php

return [
    'defaults' => ['guard' => 'api'],
    'guards' => [
        'api' => ['driver' => 'jwt', 'provider' => 'project_accounts'],
    ],
    'providers' => [
        'project_accounts' => [
            'driver' => 'eloquent',
            'model' => App\Models\ProjectAccount::class,
        ],
    ],
];
