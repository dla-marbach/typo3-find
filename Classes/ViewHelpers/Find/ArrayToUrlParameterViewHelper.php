<?php

namespace Subugoe\Find\ViewHelpers\Find;

/* * *************************************************************
 *  Copyright notice
 *
 *  (c) 2013
 *      Ingo Pfennigstorf <pfennigstorf@sub-goettingen.de>
 *      Sven-S. Porst <porst@sub.uni-goettingen.de>
 *      Göttingen State and University Library
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

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * FORK-ABWEICHUNG: Dieser ViewHelper existiert im Original (subugoe/typo3-find) nicht.
 * Er wird im Fork fuer die Ajax-Facetten-Funktionalitaet benoetigt, um ein Array
 * von aktiven Facetten in einen URL-kodierten Query-String umzuwandeln, der dann
 * fuer asynchrone Facetten-Requests verwendet wird.
 *
 * Converts a facet array to URL parameters for use in Ajax facet requests.
 */
class ArrayToUrlParameterViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('array', 'array', 'Info array', true);
    }

    public function render(): string
    {
        $newArray = [];
        foreach ($this->arguments['array'] as $facetId => $facetArray) {
            foreach ($facetArray as $facetTerm => $facetConfig) {
                $newArray['activeFacets']['facet'][$facetId][$facetTerm] = 1;
            }
        }

        return http_build_query($newArray);
    }
}
