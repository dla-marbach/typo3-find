<?php

return [
    'frontend' => [
        'Dla/Find/Ajax/Facets' => [
            'target' => \Dla\Find\Ajax\Facets::class,
            'after' => [
                'typo3/cms-frontend/site'
            ],
            'before' => [
                'typo3/cms-frontend/backend-user-authentication'
            ],
        ],
    ],
];
