<?php

/**
 * FORK-ABWEICHUNG: Diese gesamte Datei existiert im Original (subugoe/typo3-find) nicht.
 * Sie registriert die Ajax-Facetten-Middleware (Classes/Ajax/Facets.php) im TYPO3-Frontend-
 * Request-Stack. Die Middleware wird nach der Site-Aufloesung und vor der Backend-User-
 * Authentifizierung ausgefuehrt, um Facettendaten per Ajax laden zu koennen.
 */

return [
    'frontend' => [
        'Subugoe/Find/Ajax/Facets' => [
            'target' => \Subugoe\Find\Ajax\Facets::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/backend-user-authentication',
            ],
        ],
    ],
];
