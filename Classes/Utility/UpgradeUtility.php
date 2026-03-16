<?php

declare(strict_types=1);

namespace Dla\Find\Utility;

class UpgradeUtility
{
    public static function handleSolariumUpgrade(array $connectionSettings): array
    {
        trigger_error('Please read the upgrading instructions at https://github.com/dla-marbach/typo3-find/blob/main/UPGRADING.md', E_USER_DEPRECATED);

        if (false !== strpos($connectionSettings['path'], '/solr/')) {
            $connectionSettings['core'] = str_replace('/solr/', '', $connectionSettings['path']);
            $connectionSettings['core'] = str_replace('/', '', $connectionSettings['core']);
            $connectionSettings['path'] = '/';
        }

        return $connectionSettings;
    }
}
