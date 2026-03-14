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
 * Returns additional parameters needed to create links for facets.
 *
 * Arguments:
 *  - facetID: ID of the facet to create the link for
 *  - facetTerm: the value of the facet's item in question [optional in remove mode]
 *  - activeFacets: the array of active facets
 *  - mode: return an array for
 *      - add: f.link.action's »arguments«, adding a facet selection
 *       - remove: f.link.action's »argumentsToBeExcludedFromQueryString«, removing a facet selection
 *              leaving out the facetTerm parameter removes all selected items for the facet facetID
 */
class FacetLinkArgumentsViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('facetID', 'string', 'ID of the facet to determine the selection status of', true);
        $this->registerArgument(
            'facetTerm',
            'string',
            'Term of the facet item to determine the selection status of; if NULL any facet with the given facetID matches',
            false,
            null
        );
        $this->registerArgument('activeFacets', 'array', 'Array of active facets', false, []);
        $this->registerArgument('mode', 'string', 'add|remove', false, 'add');
        // FORK-ABWEICHUNG: Die Argumente 'not' und 'modifier' existieren im Original (subugoe/typo3-find)
        // nicht. Sie werden fuer die Fork-spezifische Facetten-Negierung benoetigt, die es ermoeglicht,
        // Facettenwerte mit einem "NOT"-Modifier auszuwaehlen (z.B. "alle Ergebnisse OHNE diesen Facettenwert").
        // Dies wird in Verbindung mit dem Modifier-Feature im SolrServiceProvider verwendet.
        $this->registerArgument('not', 'boolean', 'Invert facet to not.', false, '');
        $this->registerArgument('modifier', 'string', 'Choose a modifier.', false, '');
    }

    /**
     * Create the return array required to add/remove the URL parameters by
     * passing it to f.link.action's »arguments«
     * or »argumentsToBeExcludedFromQueryString«.
     */
    public function render(): array
    {
        $result = [];

        $facetID = $this->arguments['facetID'];
        $facetTerm = $this->arguments['facetTerm'];
        $activeFacets = $this->arguments['activeFacets'];
        $mode = $this->arguments['mode'];
        if ($mode === 'remove' && $activeFacets) {
            if (array_key_exists($facetID, $activeFacets)) {
                $itemToRemove = 'tx_find_find[facet][' . $facetID . ']';

                if (array_key_exists($facetTerm, $activeFacets[$facetID])) {
                    $itemToRemove .= '[' . $facetTerm . ']';
                }

                $result[] = $itemToRemove;
            }

            // Go back to page 1.
            $result[] = 'tx_find_find[page]';
        } elseif ($mode === 'add') {
            $result['facet'] = [
                $facetID => [$facetTerm => 1],
            ];
            // FORK-ABWEICHUNG: Wenn ein Modifier gesetzt ist (z.B. "not"), wird der Facettenwert
            // mit dem entsprechenden Modifier-Wert versehen, anstatt nur den Standardwert 1 zu verwenden.
            // Damit koennen Facetten im Fork negiert werden ("NOT"-Filter).
            // Im Original (subugoe/typo3-find) gibt es diese Funktionalitaet nicht.
            $modifier = $this->arguments['modifier'];
            if ($modifier) {
                $result['facet'][$facetID][str_replace('&', '%26', $facetTerm)] = $modifier;
            }
        }

        return $result;
    }
}
