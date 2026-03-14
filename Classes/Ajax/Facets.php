<?php

/**
 * FORK-ABWEICHUNG: Diese gesamte Datei existiert im Original (subugoe/typo3-find) nicht.
 * Sie implementiert die Ajax-Facetten-Funktionalitaet des Forks, die es ermoeglicht,
 * Facettendaten asynchron (per Ajax) vom Solr-Server zu laden, ohne die gesamte Seite
 * neu laden zu muessen. Dies wird ueber eine PSR-15-Middleware realisiert, die auf
 * spezielle Request-Parameter (facetId, q) reagiert und die Facettenwerte als JSON zurueckliefert.
 * Die zugehoerige Konfiguration befindet sich in Configuration/RequestMiddlewares.php.
 */

namespace Subugoe\Find\Ajax;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Facets implements MiddlewareInterface
{

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!isset($request->getQueryParams()['facetId'], $request->getQueryParams()['q'])) {
            return $response;
        }

        /** @var Site $site */
        $site = $request->getAttribute('site');
        /** @var RootlineUtility $rootlineUtility */
        $rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $site->getRootPageId());
        $rootline = $rootlineUtility->get();
        /** @var TemplateService $templateService */
        $templateService = GeneralUtility::makeInstance(TemplateService::class);
        $templateService->tt_track = 0;
        $templateService->runThroughTemplates($rootline);
        $templateService->generateConfig();
        // Now setup and constants are available like this:


        $facetConfig = $templateService->setup['plugin.']['tx_find.']['settings.']['facets.'];
        $additionalFilters = $templateService->setup['plugin.']['tx_find.']['settings.']['additionalFilters.'];

        $queryFields = $templateService->setup['plugin.']['tx_find.']['settings.']['queryFields.'];

        $facetQueries = [];

        foreach ($facetConfig as $facet) {
            if ($facet['id'] === $request->getQueryParams()['facetId'] && $facet['ajax'] == 1) {
                $currentFacetConfig = $facet;
            }
            $facetQueries[$facet['id']] = $facet['query'];
        }
        if (empty($currentFacetConfig)) {
            return $response;
        }

        $facetField = $currentFacetConfig['field'];
        $facetId = $currentFacetConfig['id'];

        include_once 'EidSettings.php';

        // TODO: Use this from typoscript configuration
        // Configuration options
        $solr_select_url = $host . $core . '/select';

        $prefix = '';
        $fq = '';
        $solrQuery = '';
        $defaultQuery = '';
        $activeFacets = $request->getQueryParams()['activeFacets'];

        $query = $request->getQueryParams()['q'];
        if ($query === 'true') {
            $findSearch = $request->getQueryParams()['tx_find_find']['q'];
            foreach ($findSearch as $fieldKey => $search) {
                foreach ($queryFields as $queryField) {
                    if ($queryField['id'] === $fieldKey) {
                        if ($fieldKey === 'default') {
                            if ($search) {
                                $solrQuery .= str_replace('%s', $search, $queryField['query']) . ' AND ';
                                $defaultQuery = str_replace('%s', $search, $queryField['query']);
                            }
                        } else {
                            if ($search) {
                                $solrQuery .= str_replace('%1$s', $search, $queryField['query']);
                            }
                        }
                    }
                }
            }
        }
        if ($defaultQuery === '') {
            $defaultQuery = str_replace('%s', '*', $queryFields['0.']['query']);
            $solrQuery = $defaultQuery . $solrQuery;
        }

        $addFacetsToSolrQuery = $solrQuery;
        if (!empty($activeFacets['facet'])) {
            foreach ($activeFacets['facet'] as $key => $value) {
                foreach ($value as $subkey => $subvalue) {
                    $addFacetsToSolrQuery .= ' AND ' . str_replace('%s', $subkey, $facetQueries[$key]);
                }
            }
        }

        $solrFq = '';
        foreach ($additionalFilters as $key => $filterQuery) {
            $solrFq .= '&fq=' . urlencode($filterQuery);
        }

        $showmissingParam = '';
        if ($currentFacetConfig['showmissing']) {
            $showmissingParam = '&facet.missing=true';
        }

        // Get relations
        $response = file_get_contents(
            $solr_select_url . '?facet.field=' . $facetField . '&facet=on&facet.mincount=1&q=' . rawurlencode($addFacetsToSolrQuery) . '&rows=0' . $solrFq . $showmissingParam,
            FALSE,
            stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'follow_location' => 0,
                    'timeout' => 1.0
                ]
            ])
        );

        $indexPageUid = $templateService->setup['plugin.']['tx_find.']['settings.']['indexPageUid'];
        $reverseFacet = $currentFacetConfig['reverseFacet'];

        $objectManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\ObjectManager::class);
        $uriBuilder = $objectManager->get(\TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder::class);

        $facetData = json_decode($response, TRUE)['facet_counts']['facet_fields'][$facetField];
        $facetResponseArray = [];

        for ($i=0;$i<count($facetData);$i++) {
            $arguments = [
                'q' => array_merge(['default' => $defaultQuery], $request->getQueryParams()['tx_find_find']['q'])
            ];
            $activeFacetsUseAsArgument = $activeFacets;

            // check if this facet value is active
            if ($activeFacets['facet'][$facetId][$facetData[$i]]){
                $arguments['facet'] = [];
                if ($activeFacets['facet'][$facetId][$facetData[$i]] == 'not') {
                    $facetItemOutput['active'] = 2;
                } else {
                    $facetItemOutput['active'] = 1;
                }
                unset($activeFacetsUseAsArgument['facet'][$facetId][$facetData[$i]]);
            } else {
                $arguments['facet'] = [$facetId => [$facetData[$i] => '1']];
                $facetItemOutput['active'] = 0;
            }

            if ($activeFacets) {
                $arguments = array_merge_recursive($arguments, $activeFacetsUseAsArgument);
            }

            $uri = $uriBuilder->reset()->setTargetPageUid($indexPageUid)->uriFor('index', $arguments, 'Search', 'find', 'find');

            $facetItemOutput['label'] = $facetData[$i];

            // if show missing is set the label is "null"
            if (!$facetItemOutput['label'] && !empty($currentFacetConfig['labelmissing'])) {
                $facetItemOutput['label'] = $currentFacetConfig['labelmissing'];
            }
            $facetItemOutput['count'] = $facetData[$i+1];
            $facetItemOutput['link'] = $uri;

            if ($reverseFacet == 1) {
                $argumentsReverse = [
                    'facet' => [$facetId => [$facetData[$i] => 'not']],
                    'q' => array_merge(['default' => $defaultQuery], $request->getQueryParams()['tx_find_find']['q'])
                ];
                if ($activeFacets) {
                    $argumentsReverse = array_merge_recursive($argumentsReverse, $activeFacets);
                }
                $uriReverse = $uriBuilder->reset()->setTargetPageUid($indexPageUid)->uriFor('index', $argumentsReverse, 'Search', 'find', 'find');
                $facetItemOutput['linkReverse'] = $uriReverse;
            }

            $facetResponseArray[] = $facetItemOutput;

            $i++;
        }

        return new JsonResponse($facetResponseArray);
    }
}
