<?php

namespace Subugoe\Find\Utility;

/* * *************************************************************
 *  Copyright notice
 *
 *  (c) 2015 Ingo Pfennigstorf <pfennigstorf@sub-goettingen.de>
 *      Göttingen State Library
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 * ************************************************************* */

/**
 * Utility for JavaScript and view helpers in the frontend.
 */
class FrontendUtility
{
    /**
     * Generates a JS assignment for the active query and its paging/facet data as `underlyingQuery` variable.
     *
     * @param mixed        $query      Query parameter(s) (string or array, depending on your usage)
     * @param array        $settings   Complete plugin or extension settings
     * @param int|null     $position   Position in result list (1-based, null if not applicable)
     * @param array        $arguments  Arguments array (request arguments or override)
     * @return string                  JS assignment or empty string
     *
     * @throws \JsonException
     */
    public static function addQueryInformationAsJavaScript(
        $query,
        array $settings,
        ?int $position = null,
        array $arguments = []
    ): string {
        if (!empty($settings['paging']['detailPagePaging'])) {
            // If the arguments contain an 'underlyingQuery' sub-array, use it
            if (array_key_exists('underlyingQuery', $arguments) && is_array($arguments['underlyingQuery'])) {
                $arguments = $arguments['underlyingQuery'];
            }

            $underlyingQuery = ['q' => $query];
            if (!empty($arguments['facet'])) {
                $underlyingQuery['facet'] = $arguments['facet'];
            }

            if ($position !== null) {
                $underlyingQuery['position'] = $position;
            }

            if (isset($arguments['count'])) {
                $underlyingQuery['count'] = $arguments['count'];
            } elseif (isset($settings['count'])) {
                $underlyingQuery['count'] = $settings['count'];
            }

            if (isset($arguments['sort'])) {
                $underlyingQuery['sort'] = $arguments['sort'];
            }

            return json_encode($underlyingQuery, JSON_THROW_ON_ERROR);
        }

        return '';
    }

    /**
     * Calculates index values for detail navigation based on a position key.
     *
     * @param array $underlyingQueryInfo Array including at least a 'position' key (1-based).
     * @return array Index info: positionIndex, previousIndex, nextIndex, resultIndexOffset
     */
    public static function getIndexes(array $underlyingQueryInfo): array
    {
        // Default to position=1 if missing
        $position = (int)($underlyingQueryInfo['position'] ?? 1);

        return [
            'positionIndex' => $position - 1,
            'previousIndex' => max($position - 2, 0),
            'nextIndex' => $position,
            'resultIndexOffset' => ($position - 1 === 0) ? 0 : 1,
        ];
    }
}
