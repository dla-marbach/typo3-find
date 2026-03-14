<?php

namespace Subugoe\Find\Service;

/* * *************************************************************
 *  Copyright notice
 *
 *  (c) 2015 Ingo Pfennigstorf <pfennigstorf@sub-goettingen.de>
 *      Goettingen State Library
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

use Psr\Log\LoggerInterface;
use Solarium\Client;
use Solarium\Component\Highlighting\Field;
use Solarium\Core\Client\Adapter\Curl;
use Solarium\Exception\HttpException;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Select\Result\Result;
use Subugoe\Find\Utility\FrontendUtility;
use Subugoe\Find\Utility\LoggerUtility;
use Subugoe\Find\Utility\SettingsUtility;
use Subugoe\Find\Utility\UpgradeUtility;
use Symfony\Component\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Service provider for Solr.
 */
class SolrServiceProvider implements ServiceProviderInterface
{
    protected ?string $action = null;

    protected array $configuration = [];

    protected Client $connection;

    protected ?string $controllerExtensionKey = null;

    protected Query $query;

    protected array $requestArguments = [];

    protected string $connectionName;

    private array $settings;

    public function setConnectionName(string $name): void
    {
        $this->connectionName = $name;
    }

    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    public function getRequestArguments(): array
    {
        return $this->requestArguments;
    }

    public function setRequestArguments(array $requestArguments): void
    {
        $this->requestArguments = $requestArguments;
    }

    public function __construct(private readonly LoggerInterface $logger) {}

    public function connect(): void
    {
        $currentConnectionSettings = $this->settings['connections'][$this->connectionName]['options'];
        // Upgrading to Solarium >= 5
        if (!array_key_exists('core', $currentConnectionSettings)) {
            $currentConnectionSettings = UpgradeUtility::handleSolariumUpgrade($currentConnectionSettings);
        }

        $connectionSettings = [
            'endpoint' => [
                $this->connectionName => [
                    'host' => $currentConnectionSettings['host'],
                    'port' => (int)$currentConnectionSettings['port'],
                    'path' => $currentConnectionSettings['path'],
                    'scheme' => $currentConnectionSettings['scheme'],
                    'core' => $currentConnectionSettings['core'],
                ],
            ],
        ];

        // create an HTTP adapter instance
        $adapter = new Curl();
        $eventDispatcher = new EventDispatcher();
        if (array_key_exists('timeout', $currentConnectionSettings)) {
            $adapter->setTimeout((int)$currentConnectionSettings['timeout']);
        }

        // create a client instance
        $client = new Client($adapter, $eventDispatcher, $connectionSettings);

        $this->setConnection($client);
        $this->testConnection();
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    /**
     * Main starting point for blank index action.
     */
    public function getDefaultQuery(): array
    {
        $this->createQueryForArguments($this->getRequestArguments());
        $error = null;
        $resultSet = null;

        try {
            $resultSet = $this->connection->execute($this->query);
        } catch (HttpException $httpException) {
            $this->logger->error(
                'Solr Exception (Timeout?)',
                [
                    'requestArguments' => $this->getRequestArguments(),
                    'exception' => LoggerUtility::exceptionToArray($httpException),
                ]
            );

            $error = ['solr' => $httpException];
        }

        return [
            'results' => $resultSet,
            'error' => $error,
        ];
    }

    public function getDocumentById(string $id): array
    {
        $arguments = $this->getRequestArguments();

        $assignments = [];
        if ($this->settings['paging']['detailPagePaging'] && array_key_exists('underlyingQuery', $arguments)) {
            // If underlying query has been sent, fetch more data to enable paging arrows.
            $underlyingQueryInfo = $arguments['underlyingQuery'];

            $index = FrontendUtility::getIndexes($underlyingQueryInfo);

            foreach ($arguments['underlyingQuery'] as $key => $value) {
                $arguments[$key] = $value;
            }

            $this->createQueryForArguments($arguments);
            $this->query->setStart($index['previousIndex']);
            $this->query->setRows($index['nextIndex'] - $index['previousIndex'] + 1);

            $assignments = $this->getRecordsWithUnderlyingQuery($assignments, $index, $id, $arguments);
        } else {
            // Without underlying query information, just get the record specified.
            $assignments = $this->getTheRecordSpecified($id, $assignments);
        }

        return $assignments;
    }

    /**
     * Returns whether extended search should be used or not.
     */
    public function isExtendedSearch(): bool
    {
        $result = false;

        if (array_key_exists('extended', $this->requestArguments)) {
            // Show extended search when told so by the »extended« argument.
            $result = ((bool)$this->requestArguments['extended']);
        } elseif (array_key_exists('q', $this->requestArguments)) {
            foreach ($this->settings['queryFields'] as $fieldInfo) {
                if (array_key_exists('extended', $fieldInfo)
                    && array_key_exists($fieldInfo['id'], $this->requestArguments['q'])
                    && $this->requestArguments['q'][$fieldInfo['id']]
                ) {
                    // Check if the request argument is an array itself (appies to field type "Range")
                    if (is_array($this->requestArguments['q'][$fieldInfo['id']])) {
                        foreach ($this->requestArguments['q'][$fieldInfo['id']] as $key => $value) {
                            if ($value !== '') {
                                $result = true;
                                break;
                            }
                        }
                    } else {
                        $result = true;
                        break;
                    }
                }
            }
        }

        return $result;
    }

    public function search($query): void
    {
        // TODO: Implement search() method.
    }

    public function setAction(string $actionName): void
    {
        $this->action = $actionName;
    }

    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function setConfigurationValue($key, $value): void
    {
        $this->configuration[$key] = $value;
    }

    public function setControllerExtensionKey(string $key): void
    {
        $this->controllerExtensionKey = $key;
    }

    public function setCounter(): void
    {
        $this->setConfigurationValue('counterStart', $this->counterStart());
        $this->setConfigurationValue('counterEnd', $this->counterEnd());
    }

    public function suggestQuery(array $settings): array
    {
        $query = $this->getConnection()->createSuggester();
        $results = [];
        if (array_key_exists('q', $settings)) {
            $query->setQuery($settings['q']);
            if ($settings['dictionary']) {
                $query->setDictionary($settings['dictionary']);
            }

            $this->addFacetFilters($settings);
            $solrResults = $this->getConnection()->execute($query)->getResults();
            foreach ($solrResults as $suggestions) {
                $results = array_merge($results, $suggestions->getSuggestions());
            }
        }

        // TODO: Error message in JSON?

        return $results;
    }

    protected function addEDisMax(): void
    {
        $this->query->getEDisMax();
    }

    /**
     * Adds filter queries for active facets to $query.
     *
     * @param array $arguments request arguments
     */
    protected function addFacetFilters(array $arguments): array
    {
        $activeFacets = $this->getActiveFacets($arguments);
        $activeFacetsForTemplate = [];
        foreach ($activeFacets as $facetID => $facets) {
            // FORK-ABWEICHUNG: Multiselect-Facetten (multi_select_facet)
            // Diese Variablen sammeln die Facettenbegriffe fuer Multiselect-Facetten,
            // damit sie zu einer einzigen Solr-Query zusammengefasst werden koennen.
            // Im Original werden Facettenbegriffe immer einzeln als FilterQuery gesetzt.
            // Der Fork erlaubt es, mehrere Facettenwerte zu einer einzigen Query zu kombinieren,
            // was fuer die Oder-Verknuepfung mehrerer Facettenwerte benoetigt wird.
            $concatFacetTerm = '';
            $i = 0;

            foreach ($facets as $facetTerm => $facetInfo) {
                $facetQuery = $this->getFacetQuery($this->getFacetConfig($facetID), $facetInfo['term']);

                // FORK-ABWEICHUNG: Negierbare Facetten (modifier "not")
                // Erlaubt es, einen Facettenfilter zu negieren, indem "NOT" vorangestellt wird.
                // Im Original gibt es keine Moeglichkeit, Facetten zu negieren.
                // Der Fork speichert in setActiveFacetSelectionForID() ein 'modifier'-Flag,
                // das hier ausgewertet wird, um die Solr-Query mit NOT zu versehen.
                // Dies wird fuer Ausschlussfilter in der Facettennavigation benoetigt.
                if (isset($facetInfo['modifier']) && $facetInfo['modifier'] === 'not') {
                    $facetQuery = 'NOT ' . $facetQuery;
                }

                // FORK-ABWEICHUNG: Multiselect-Facetten – Zaehlung und Zusammenfassung
                // Fuer multi_select_facet-Typen werden die Facettenbegriffe gesammelt und erst
                // beim letzten Element als kombinierte Query eingefuegt (siehe unten im else-Zweig).
                // Im Original existiert kein multi_select_facet-Typ.
                if (isset($facetInfo['config']['facettype']) && $facetInfo['config']['facettype'] === 'multi_select_facet') {
                    if ($concatFacetTerm === '') {
                        $concatFacetTerm = $facetTerm;
                    } else {
                        $concatFacetTerm = $concatFacetTerm . ' ' . $facetTerm;
                    }
                    $i++;
                }

                if (array_key_exists('queryStyle', $facetInfo['config']) && $facetInfo['config']['queryStyle'] === 'and') {
                    // TODO: Do we really use this part of the condition? Can it be removed?
                    // Alternative query style: adding a conjunction to the main query.
                    // Can be useful when using {!join} to filter on the underlying
                    // records instead of the joined ones.
                    $queryString = $this->query->getQuery();
                    if ($queryString) {
                        $queryString .= ' ' . Query::QUERY_OPERATOR_AND . ' ';
                    }

                    $queryString .= $facetQuery;
                    $this->query->setQuery($queryString);
                } else {
                    // Add a filter query by default.

                    // Add tag/key when configured to excludeOwnFilter for this facet.
                    // Do not add it otherwise as the additional {!tag …} prepended to the Solr query
                    // will break usage of {!join …} in the query.
                    $queryInfo = ['key' => 'facet-' . $facetID . '-' . $facetTerm];
                    if (array_key_exists('excludeOwnFilter', $facetInfo['config']) && $facetInfo['config']['excludeOwnFilter'] && $facetQuery) {
                        $queryInfo['tag'] = $this->tagForFacet($facetID);
                    }

                    // If facet.missing is active and facet is selected
                    // set solr query to exclude all known facet values
                    if (array_key_exists('labelMissing', $facetInfo['config']) && $facetTerm === $facetInfo['config']['labelMissing']) {
                        $this->query->createFilterQuery($queryInfo)
                            ->setQuery('-' . str_replace('("%s")', '[* TO *]', $facetInfo['config']['query']));
                    } else {
                        // FORK-ABWEICHUNG: Multiselect-Facetten – kombinierte Query-Erzeugung
                        // Wenn es sich um eine multi_select_facet handelt, wird die FilterQuery
                        // erst erzeugt, wenn alle Facettenbegriffe gesammelt wurden (letztes Element).
                        // Die gesammelten Begriffe werden in das Query-Pattern eingesetzt, sodass
                        // Solr sie als Oder-Verknuepfung interpretiert.
                        // Im Original wird jeder Facettenbegriff einzeln als FilterQuery gesetzt.
                        if (isset($facetInfo['config']['facettype']) && $facetInfo['config']['facettype'] === 'multi_select_facet') {
                            if ($i === count($facets)) {
                                $facetQuery = str_replace('"%s"', $concatFacetTerm, $facetInfo['config']['query']);
                                $this->query->createFilterQuery($queryInfo)->setQuery($facetQuery);
                            } else {
                                continue;
                            }
                        } else {
                            $this->query->createFilterQuery($queryInfo)
                                ->setQuery($facetQuery);
                        }
                    }
                }

                $activeFacetsForTemplate[$facetID][$facetTerm] = $facetInfo;
            }
        }

        return $activeFacetsForTemplate;
    }

    /**
     * Adds facet queries to $query from setup in TypoScript.
     * Provides the facet setup enriched with the default values when no configuration
     * is present in the »facets« template variable.
     */
    protected function addFacetQueries(): void
    {
        // FORK-ABWEICHUNG: Stats-Query fuer Datumsbereichs-Facetten (date_range)
        // Der Fork klont die aktuelle Query, um eine Stats-Abfrage an Solr zu senden.
        // Diese liefert den minimalen Datumswert, der fuer die dynamische Berechnung
        // des Facetten-Gaps (Zeitintervall) bei date_range-Facetten benoetigt wird.
        // Im Original gibt es keinen date_range-Facettentyp und keine Stats-Abfrage.
        $statsquery = clone $this->query;
        $statsquery->setStart(0)->setRows(0);

        $facetConfiguration = $this->settings['facets'];

        if ($facetConfiguration) {
            $facetSet = $this->query->getFacetSet();
            foreach ($facetConfiguration as $key => $facet) {
                if (array_key_exists('id', $facet)) {
                    $facetID = $facet['id'];

                    // start with defaults and overwrite with specific facet configuration
                    $facet = array_merge($this->settings['facetDefaults'], $facet);
                    $facetConfiguration[$key] = $facet;

                    $queryForFacet = null;
                    if (array_key_exists('facetQuery', $facet)) {
                        $queryForFacet = $facetSet->createFacetMultiQuery($facetID);
                        foreach ($facet['facetQuery'] as $facetQueryIndex => $facetQuery) {
                            if (array_key_exists('id', $facetQuery) && array_key_exists('query', $facetQuery)) {
                                $queryForFacet->createQuery($facetQuery['id'], $facetQuery['query']);
                            } else {
                                $this->logger->error(
                                    sprintf('TypoScript facet »%s«, facetQuery %s does not have the required keys »id« and »query«. Ignoring this facetQuery.', $facetID, $facetQueryIndex),
                                    [
                                        'facetQuery' => $facetQuery,
                                        'facetConfiguration' => $facetConfiguration,
                                    ]
                                );
                            }
                        }

                        if (array_key_exists('excludeOwnFilter', $facet) && (int)$facet['excludeOwnFilter'] === 1) {
                            $queryForFacet->addExclude($this->tagForFacet($facetID));
                        }
                    } elseif (array_key_exists('facettype', $facet) && $facet['facettype'] === 'date_range') {
                        // FORK-ABWEICHUNG: Datumsbereichs-Facette (date_range)
                        // Erzeugt eine Solr-Range-Facette mit dynamisch berechnetem Gap.
                        // Ueber eine Stats-Abfrage wird der minimale Datumswert ermittelt.
                        // Daraus wird die Zeitspanne bis heute berechnet und in ca. 50 Intervalle
                        // aufgeteilt, wobei der Gap so gewaehlt wird, dass die Zeitspanne
                        // gleichmaessig teilbar ist.
                        // Im Original existiert kein date_range-Facettentyp – dort werden nur
                        // einfache Feld-Facetten und facetQuery-Facetten unterstuetzt.
                        if ($facet['start'] && $facet['end'] && $facet['gap']) {
                            try {
                                $stats = $statsquery->getStats();
                                $stats->createField('facet_time_stat');

                                $resultset = $this->connection->select($statsquery);

                                $statsResult = $resultset->getStats();
                                $minValue = $statsResult->getResult('facet_time_stat')->getMin();
                            } catch (HttpException $exception) {
                                // preset to year 0, if stats query failed
                                $minValue = '0000-01-01';
                            } catch (\Exception $e) {
                                // preset to year 0, if stats query failed
                                $minValue = '0000-01-01';
                            }

                            if (!$minValue) {
                                $minValue = '0000-01-01';
                            }

                            $date = new \DateTime($minValue);
                            $nowDate = new \DateTime('now');

                            $years = date_diff($date, $nowDate);

                            if ($years->y < 50) {
                                $gap = 1;
                            } else {
                                $gap = round($years->y / 50);
                            }

                            // calculate start date so the gap divides evenly
                            $mod = $years->y % $gap;
                            $adding = $gap - $mod;

                            $startTimeYears = $years->y + $adding;

                            $start = 'NOW/YEAR-' . $startTimeYears . 'YEARS';

                            $end = $nowDate
                                ->add(new \DateInterval('P1Y'))
                                ->format('Y-m-d\TH:i:s\Z');

                            $queryForFacet = $facetSet->createFacetRange($facet['field'] ? $facetID : $facet['field']);
                            $queryForFacet->setField($facet['field'] ?: $facetID)
                                ->setGap('+' . $gap . 'YEAR')
                                ->setStart($start)
                                ->setEnd($end);
                        }
                    } else {
                        $queryForFacet = $facetSet->createFacetField($facetID);
                        $queryForFacet->setField($facet['field'] ?: $facetID)
                            ->setMinCount($facet['fetchMinimum'])
                            ->setLimit($facet['fetchMaximum'])
                            ->setSort($facet['sortOrder']);
                    }

                    if (array_key_exists('excludeOwnFilter', $facet) && $facet['excludeOwnFilter'] === 1) {
                        $queryForFacet->addExclude($this->tagForFacet($facetID));
                    }

                    if (array_key_exists('showMissing', $facet) && $facet['showMissing'] === 1) {
                        $queryForFacet->setMissing(true);
                    }

                    // FORK-ABWEICHUNG: Unterstuetzung fuer 'showmissing' (Kleinschreibung)
                    // Neben dem originalen 'showMissing' (camelCase) wird hier auch die
                    // kleingeschriebene Variante 'showmissing' unterstuetzt.
                    // Dies ist fuer Abwaertskompatibilitaet mit aelteren TypoScript-Konfigurationen
                    // im Fork noetig, die den Schluessel in Kleinschreibung verwenden.
                    // Im Original wird nur 'showMissing' (camelCase) geprueft.
                    if (array_key_exists('showmissing', $facet) && (int)$facet['showmissing'] === 1) {
                        $queryForFacet->setMissing(true);
                    }
                } else {
                    $this->logger->warning(
                        sprintf('TypoScript facet %s does not have the required key »id«. Ignoring this facet.', $key),
                        [
                            'facet' => $facet,
                            'facetConfiguration' => $facetConfiguration,
                        ]
                    );
                }
            }
        }

        $this->setConfigurationValue('facets', $facetConfiguration);
    }

    protected function addFeatures(): void
    {
        if (array_key_exists('features', $this->settings) && $this->settings['features']['eDisMax']) {
            $this->addEDisMax();
        }
    }

    /**
     * Sets up $query’s highlighting according to TypoScript settings.
     * Unicode Private Use Area Codepoints U+EEEE and U+EEEF are used to mark
     * the highlight to better deal with field contents that contain markup
     * themselves.
     *
     * @param array $arguments request arguments
     */
    protected function addHighlighting(array $arguments): void
    {
        $highlightConfig = SettingsUtility::getMergedSettings('highlight', $this->settings);

        if ($highlightConfig && $highlightConfig['fields'] && $highlightConfig['fields'] !== []) {
            $highlight = $this->query->getHighlighting();

            // Configure highlight queries.
            if (array_key_exists('query', $highlightConfig) && $highlightConfig['query']) {
                $queryWords = [];
                if ($highlightConfig['useQueryTerms'] && array_key_exists('q', $arguments)) {
                    $queryParameters = $arguments['q'];
                    foreach ($this->settings['queryFields'] as $fieldInfo) {
                        $fieldID = $fieldInfo['id'];
                        if ($fieldID && $queryParameters[$fieldID]) {
                            $queryArguments = $queryParameters[$fieldID];
                            $queryTerms = null;
                            if (is_array($queryArguments) && array_key_exists(
                                'alternate',
                                $queryArguments
                            ) && array_key_exists('queryAlternate', $fieldInfo)
                            ) {
                                if (array_key_exists('term', $queryArguments)) {
                                    $queryTerms = $queryArguments['term'];
                                }
                            } else {
                                $queryTerms = $queryArguments;
                            }

                            if (!is_array($queryTerms)) {
                                $queryTerms = [$queryTerms];
                            }

                            foreach ($queryTerms as $queryTerm) {
                                if (!$fieldInfo['noescape']) {
                                    if ($fieldInfo['phrase']) {
                                        $queryTerm = $this->query->getHelper()->escapePhrase($queryTerm);
                                    } else {
                                        $queryTerm = $this->query->getHelper()->escapeTerm($queryTerm);
                                    }
                                }

                                $queryWords[] = $queryTerm;
                            }
                        }
                    }
                }

                $queryWords = array_filter($queryWords);

                if ($highlightConfig['useFacetTerms']) {
                    foreach ($this->getActiveFacets($arguments) as $facets) {
                        foreach (array_keys($facets) as $facetTerm) {
                            $queryWords[] = $this->query->getHelper()->escapePhrase($facetTerm);
                        }
                    }
                }

                $queryComponents = [];
                foreach ($queryWords as $queryWord) {
                    $queryComponents[] = '(' . sprintf($highlightConfig['query'], $queryWord) . ')';
                }

                $queryString = implode(' OR ', $queryComponents);

                $highlight->setQuery($queryString);
            }

            // Configure highlight fields.
            $highlight->addFields(implode(',', $highlightConfig['fields']));

            // Configure the fragment length.
            if (array_key_exists('fragsize', $highlightConfig)) {
                $highlight->setFragSize((int)$highlightConfig['fragsize']);
            }

            // Set up alternative fields.
            if (array_key_exists('alternateFields', $highlightConfig) && $highlightConfig['alternateFields']) {
                foreach ($highlightConfig['alternateFields'] as $fieldName => $alternateFieldName) {
                    $highlightField = $highlight->getField($fieldName);
                    if ($highlightField instanceof Field) {
                        $highlightField->setAlternateField($alternateFieldName);
                    }
                }
            }

            // Set up prefix and postfix.
            $highlight->setSimplePrefix('\ueeee');
            $highlight->setSimplePostfix('\ueeef');
        }

        $this->setConfigurationValue('highlight', $highlightConfig);
    }

    /**
     * Provides result count information in the configuration »resultCountOptions«.
     *
     * For the key »menu« it contains an array with keys and values the result count
     * that is suitable for use in the f:form.select View Helper’s options argument.
     * For the key »default« it contains the default number of results.
     * For the key »selected« it contains the the selected number of results.
     *
     * @param array $arguments request arguments
     */
    protected function addResultCountOptionsToTemplate(array $arguments): void
    {
        $resultCountOptions = ['menu' => []];

        if (is_array($this->settings['paging']['menu'])) {
            ksort($this->settings['paging']['menu']);
            foreach ($this->settings['paging']['menu'] as $resultCount) {
                $resultCountOptions['menu'][$resultCount] = $resultCount;
            }

            $resultCountOptions['default'] = $this->settings['paging']['perPage'];

            if ($arguments['count'] && array_key_exists($arguments['count'], $resultCountOptions['menu'])) {
                $resultCountOptions['selected'] = $arguments['count'];
            } else {
                $resultCountOptions['selected'] = $resultCountOptions['default'];
            }
        }

        $this->setConfigurationValue('resultCountOptions', $resultCountOptions);
    }

    /**
     * Provides sorting information in the template variable »sortOptions«.
     *
     * For the key »menu« it contains an array with keys: sort criteria and
     * values: localised labels that is suitable for use in the f:form.select
     * View Helper’s options argument.
     * For the key »default« it contains the default sort order string.
     * For the key »selected« it contains the selected sort order string.
     *
     * @param array $arguments request arguments
     */
    protected function addSortOrdersToTemplate(array $arguments): void
    {
        $sortOptions = ['menu' => []];

        if (is_array($this->settings['sort'])) {
            ksort($this->settings['sort']);
            foreach ($this->settings['sort'] as $sortOptionIndex => $sortOption) {
                if (array_key_exists('id', $sortOption) && array_key_exists('sortCriteria', $sortOption)) {
                    $localisationKey = 'LLL:' . $this->settings['languageRootPath'] . 'locallang-form.xlf:input.sort-' . $sortOption['id'];
                    $localisedLabel = LocalizationUtility::translate(
                        $localisationKey,
                        $this->getControllerExtensionKey()
                    );
                    if (!$localisedLabel) {
                        $localisedLabel = $sortOption['id'];
                    }

                    $sortOptions['menu'][$sortOption['sortCriteria']] = $localisedLabel;

                    if ($sortOption['id'] === 'default') {
                        $sortOptions['default'] = $sortOption['sortCriteria'];
                    }
                } else {
                    $this->logger->warning(
                        sprintf('TypoScript sort option »%s« does not have the required keys »id« and »sortCriteria. Ignoring this setting.', $sortOptionIndex),
                        [
                            'sortOption' => $sortOption,
                        ]
                    );
                }
            }

            if (array_key_exists('sort', $arguments) && array_key_exists($arguments['sort'], $sortOptions['menu']) && $arguments['sort']) {
                $sortOptions['selected'] = $arguments['sort'];
            } elseif (array_key_exists('default', $sortOptions)) {
                $sortOptions['selected'] = $sortOptions['default'];
            } else {
                $sortOptions['selected'] = 'is asc';
            }
        }

        $this->setConfigurationValue('sortOptions', $sortOptions);
    }

    /**
     * Checks that $sortString is well-formatted and adds the sort conidition
     * defined by it to $query.
     * Adds feedback about invalid sort string format to the page.
     */
    protected function addSortStringForQuery(string $sortString): void
    {
        if ($sortString !== '') {
            $sortCriteria = explode(',', $sortString);
            foreach ($sortCriteria as $sortCriterion) {
                $sortCriterionParts = explode(' ', $sortCriterion);
                if (count($sortCriterionParts) === 2) {
                    $sortDirection = Query::SORT_ASC;
                    if ($sortCriterionParts[1] === 'desc') {
                        $sortDirection = Query::SORT_DESC;
                    } elseif ($sortCriterionParts[1] !== 'asc') {
                        $this->logger->warning(sprintf('sort criterion »%s«’s sort direction is »%s« It should be »asc« or »desc«. Ignoring it.', $sortCriterion, $sortCriterionParts[1]));
                        continue;
                    }

                    $this->query->addSort($sortCriterionParts[0], $sortDirection);
                } else {
                    $this->logger->warning('sort criterion »%s« does not have the required form »fieldName [asc|desc]«. Ignoring it.', [$sortCriterion]);
                }
            }
        }
    }

    /**
     * Adds filter queries configured in TypoScript to $query.
     */
    protected function addTypoScriptFilters(): SolrServiceProvider
    {
        if (!empty($this->settings['additionalFilters'])) {
            foreach ($this->settings['additionalFilters'] as $key => $filterQuery) {
                $this->query->createFilterQuery('additionalFilter-' . $key)
                    ->setQuery($filterQuery);
            }
        }

        return $this;
    }

    /**
     * Returns the number of the last result on the page.
     */
    protected function counterEnd(): int
    {
        return $this->getOffset() + $this->getCount();
    }

    /**
     * Returns the number of the first result on the page.
     */
    protected function counterStart(): int
    {
        return $this->getOffset() + 1;
    }

    /**
     * Creates a blank query, sets up TypoScript filters and adds it to the view.
     */
    protected function createQuery(): void
    {
        $this->query = $this->connection->createSelect();
        $this->addFeatures();
        $this->addTypoScriptFilters();
        $this->addDefaultQueryOperator();

        $this->setConfigurationValue('solarium', $this->query);
    }

    /**
     * Creates a query configured with all parameters set in the request’s arguments.
     *
     * @param array $arguments request arguments
     */
    protected function createQueryForArguments(array $arguments): void
    {
        $this->createQuery();

        // Build query string.
        $rawQueryParameters = [];
        if (array_key_exists('q', $arguments)) {
            $rawQueryParameters = $arguments['q'];
        }

        // Process parameters to eliminate empty values
        $queryParameters = [];
        if (is_array($rawQueryParameters) && $rawQueryParameters !== []) {
            foreach ($rawQueryParameters as $key => $value) {
                if (is_array($value) && array_filter($value) !== []) {
                    $queryParameters[$key] = array_filter($value);
                } elseif (!empty($value) && !is_array($value)) {
                    $queryParameters[$key] = $value;
                }
            }
        }

        $queryComponents = $this->queryComponentsForQueryParameters($queryParameters);
        $queryString = implode(' ' . Query::QUERY_OPERATOR_AND . ' ', $queryComponents);

        $this->query->setQuery($queryString);

        $this->setConfigurationValue('query', $queryParameters);
        $this->setConfigurationValue('queryString', $queryString);

        $this->setFields($arguments);
        $this->setRange($arguments);
        $this->setSortOrder($arguments);

        $this->addHighlighting($arguments);
        $this->setConfigurationValue('activeFacets', $this->addFacetFilters($arguments));
        $this->addFacetQueries();
    }

    protected function getAction(): ?string
    {
        return $this->action;
    }

    /**
     * Returns array with information about active facets.
     *
     * @param array $arguments request arguments
     *
     * @return array of arrays with information about active facets
     */
    protected function getActiveFacets(array $arguments): array
    {
        $activeFacets = [];

        // Add facets activated by default.
        foreach ($this->settings['facets'] as $facet) {
            if (!empty($facet['selectedByDefault'])) {
                $this->setActiveFacetSelectionForID($activeFacets, $facet['id'], $facet['selectedByDefault']);
            }
        }

        // Add facets activated by query parameters.
        if (array_key_exists('facet', $arguments)) {
            foreach ($arguments['facet'] as $facetID => $facetSelection) {
                $this->setActiveFacetSelectionForID($activeFacets, $facetID, $facetSelection);
            }
        }

        return $activeFacets;
    }

    protected function getConnection(): Client
    {
        return $this->connection;
    }

    protected function getControllerExtensionKey(): ?string
    {
        return $this->controllerExtensionKey;
    }

    /**
     * Returns the number of results per page using the first of:
     * * query parameter »count«
     * * TypoScript setting »paging.perPage«
     * limited by the setting »paging.maximumPerPage«.
     *
     * @param array|null $arguments overrides $this->requestArguments if set
     */
    protected function getCount(?array $arguments = null): int
    {
        if ($arguments === null) {
            $arguments = $this->getRequestArguments();
        }

        $count = (int)$this->settings['paging']['perPage'];

        if (array_key_exists('count', $arguments)) {
            $count = (int)$this->requestArguments['count'];
        }

        $maxCount = (int)$this->settings['paging']['maximumPerPage'];
        $count = min([$count, $maxCount]);

        $this->setConfigurationValue('count', $count);

        return $count;
    }

    /**
     * Returns the facet configuration for the given $id.
     */
    protected function getFacetConfig(string $id): ?array
    {
        $config = null;

        foreach ($this->settings['facets'] as $facet) {
            if (array_key_exists('id', $facet) && $facet['id'] === $id) {
                $config = $facet;
                break;
            }
        }

        return $config;
    }

    /**
     * Returns query for the given facet $ID and $term based on the facet’s
     * configuration.
     */
    protected function getFacetQuery(array $facetConfig, string $queryTerm): ?string
    {
        $queryString = null;

        if ($facetConfig !== []) {
            if (array_key_exists('facetQuery', $facetConfig)) {
                // Facet queries are configured: use one of them.
                foreach ($facetConfig['facetQuery'] as $facetQueryConfig) {
                    if ($facetQueryConfig['id'] === $queryTerm) {
                        $queryString = $facetQueryConfig['query'];
                        break;
                    }
                }

                if ($queryString === null) {
                    $this->logger->info(
                        sprintf('Results for Facet »%s« with facetQuery ID »%s« were requested, but this facetQuery is not configured. Building a generic facet query instead.', $facetConfig['id'], $queryTerm),
                        [
                            'requestArguments' => $this->requestArguments,
                            'facetConfig' => $facetConfig,
                            'queryTerm' => $queryTerm,
                        ]
                    );
                }
            }

            if ($queryString === null) {
                // No Facet queries applicable: build the query.
                if (array_key_exists('query', $facetConfig)) {
                    $queryPattern = $facetConfig['query'];
                } else {
                    $queryPattern = ($facetConfig['field'] ?: $facetConfig['id']) . ':%s';
                }

                // Hack: convert strings »RANGE XX TO YY« Solr style range queries »[XX TO YY]«
                // (because PHP loses ] in array keys during URL parsing)
                $queryTerm = preg_replace('#RANGE (.*) TO (.*)#', '[\1 TO \2]', $queryTerm);
                $queryString = sprintf($queryPattern, $queryTerm);
            }
        } else {
            $this->logger->warning(
                'A non-configured facet was selected. Ignoring it.',
                ['requestArguments' => $this->requestArguments]
            );
        }

        return $queryString;
    }

    /**
     * Returns the index of the first row to return.
     *
     * @param array|null $arguments overrides $this->requestArguments if set
     */
    protected function getOffset(?array $arguments = null): int
    {
        if ($arguments === null) {
            $arguments = $this->requestArguments;
        }

        $offset = 0;

        if (array_key_exists('start', $arguments)) {
            $offset = (int)$arguments['start'];
        } elseif (array_key_exists('page', $arguments)) {
            $offset = ((int)$arguments['page'] - 1) * $this->getCount();
        }

        $this->setConfigurationValue('offset', $offset);

        return $offset;
    }

    protected function getRecordsWithUnderlyingQuery(array $assignments, array $index, $id, $arguments): array
    {
        $connection = $this->getConnection();

        try {
            /** @var Result $selectResults */
            $selectResults = $connection->execute($this->query);

            if ($selectResults->getNumFound() > 0) {
                $assignments['results'] = $selectResults;
                $resultSet = $selectResults->getDocuments();

                // the actual result is at position 0 (for the first document) or 1 (otherwise).
                $document = $resultSet[$index['resultIndexOffset']];
                if ($document['id'] === $id) {
                    $assignments['document'] = $document;
                    if ($index['resultIndexOffset'] !== 0) {
                        $assignments['document-previous'] = $resultSet[0];
                        $assignments['document-previous-number'] = $index['previousIndex'] + 1;
                    }

                    $nextResultIndex = 1 + $index['resultIndexOffset'];
                    if (count($resultSet) > $nextResultIndex) {
                        $assignments['document-next'] = $resultSet[$nextResultIndex];
                        $assignments['document-next-number'] = $index['nextIndex'] + 1;
                    }
                } else {
                    $this->logger->error(
                        sprintf('»detail« action query with underlying query could not retrieve record id »%d«.', $id),
                        ['arguments' => $arguments]
                    );
                }
            } else {
                $this->logger->error('»detail« action query with underlying query returned no results.', ['arguments' => $arguments]);
            }
        } catch (HttpException $httpException) {
            $this->logger->error(
                'Solr Exception (Timeout?)',
                [
                    'arguments' => $arguments,
                    'exception' => LoggerUtility::exceptionToArray($httpException),
                ]
            );
        }

        return $assignments;
    }

    protected function getTheRecordSpecified(string $id, array $assignments): array
    {
        $connection = $this->getConnection();

        $this->createQuery();
        $escapedID = $this->query->getHelper()->escapeTerm($id);
        $this->query->setQuery('id:' . $escapedID);
        try {
            /** @var Result $selectResults */
            $selectResults = $connection->execute($this->query);

            if ($selectResults->getNumFound() > 0) {
                $assignments['results'] = $selectResults;
                $resultSet = $selectResults->getDocuments();
                $assignments['document'] = $resultSet[0];
            } else {
                $this->logger->error(sprintf('»detail« action query for id »%d« returned no results.', $id), ['arguments' => $this->getRequestArguments()]);
            }
        } catch (HttpException $httpException) {
            $this->logger->error(
                'Solr Exception (Timeout?)',
                [
                    'arguments' => $this->getRequestArguments(),
                    'exception' => LoggerUtility::exceptionToArray($httpException),
                ]
            );
        }

        return $assignments;
    }

    /**
     * Takes the array of search query parameters and builds an array of Solr
     * search strings from it, using the »queryFields« configuration from TypoScript.
     * These search strings need to be ANDed together for the complete query.
     */
    protected function queryComponentsForQueryParameters(array $queryParameters): array
    {
        $queryComponents = [];

        $queryFields = $this->settings['queryFields'];
        foreach ($queryFields as $fieldInfo) {
            $fieldID = $fieldInfo['id'];
            if ($fieldID && array_key_exists($fieldID, $queryParameters) && $queryParameters[$fieldID] !== null) {
                // Extract array of query terms from the different structures:
                // a) just a single string (e.g. text field)
                // b) array of strings (e.g. date range field)
                // c) single field with additional configuration (e.g. text field with alternate query)
                $queryArguments = $queryParameters[$fieldID];
                $queryAlternate = null;
                $queryTerms = null;
                if (is_array($queryArguments) && array_key_exists('alternate', $queryArguments) && array_key_exists('queryAlternate', $fieldInfo)) {
                    $queryAlternate = $queryArguments['alternate'];
                    if (array_key_exists('term', $queryArguments)) {
                        $queryTerms = $queryArguments['term'];
                    }
                } else {
                    $queryTerms = $queryArguments;
                }

                if (isset($queryTerms) && !is_array($queryTerms)) {
                    $queryTerms = [$queryTerms];
                }

                // Fill in pre-configured default values if they exist and the field is empty.
                if (array_key_exists('default', $fieldInfo)) {
                    $defaults = $fieldInfo['default'];
                }

                if (isset($defaults)) {
                    if (!is_array($defaults)) {
                        $defaults = [$defaults];
                    }

                    foreach ($defaults as $defaultKey => $default) {
                        if (!array_key_exists($defaultKey, $queryTerms)) {
                            $queryTerms[$defaultKey] = $default;
                        }
                    }
                }

                // Escape all arguments unless told not to do so.
                if (!$fieldInfo['noescape']) {
                    $escapedQueryTerms = [];
                    if (is_array($queryTerms) && $queryTerms !== [] && count($queryTerms) > 1) {
                        foreach ($queryTerms as $key => $term) {
                            if ($fieldInfo['phrase']) {
                                $escapedQueryTerms[$key] = $this->query->getHelper()->escapePhrase($term);
                            } else {
                                $escapedQueryTerms[$key] = $this->query->getHelper()->escapeTerm($term);
                            }
                        }

                        $queryTerms = $escapedQueryTerms;
                    }
                }

                // Get the query format and insert the query term.
                $queryFormat = '';
                if (!$queryAlternate) {
                    $queryFormat = $fieldInfo['query'];
                } elseif (array_key_exists($queryAlternate, $fieldInfo['queryAlternate'])) {
                    $queryFormat = $fieldInfo['queryAlternate'][$queryAlternate];
                }

                if (empty($queryFormat)) {
                    $queryFormat = $fieldID . ':%s';
                }

                ksort($queryTerms);

                $magicFieldPrefix = '';

                if ((array_key_exists('luceneMatchVersionNumber', $this->settings) && (int)$this->settings['luceneMatchVersionNumber'] < 8) || (!array_key_exists('luceneMatchVersionNumber', $this->settings))) {
                    $magicFieldPrefix = '_query_:';
                }

                if ($this->settings['features']['eDisMax']) {
                    $magicFieldPrefix .= '{!edismax}';
                }

                if ((int)$fieldInfo['noescape'] === 2) {
                    $chars = explode(',', (string)$fieldInfo['escapechar']);
                    foreach ($queryTerms as $key => $term) {
                        $queryTerm = $term;
                        foreach ($chars as $char) {
                            $queryTerm = str_replace($char, '\\' . $char, $queryTerm);
                        }

                        // FORK-ABWEICHUNG: Zeichen-Ersetzung nach Escaping und Solr-Boost-Faktor
                        // Erlaubt es, nach dem Escaping bestimmte Zeichen(ketten) im Query-Term
                        // zu ersetzen und optional einen Solr-Boost-Faktor (^gewicht) anzuhaengen.
                        // Die Konfiguration erfolgt ueber fieldInfo['replaceAfterEscape'], das ein
                        // Array von Ersetzungsregeln mit optionalem 'boost'-Schluessel enthaelt.
                        // Im Original wird nach dem Escaping keine weitere Ersetzung vorgenommen
                        // und es gibt keine Moeglichkeit, Boost-Faktoren pro Feld-Ersetzung zu setzen.
                        // Dies wird im Fork fuer die gezielte Gewichtung bestimmter Dokument-IDs
                        // oder Terme in der Suche benoetigt.
                        if (!empty($fieldInfo['replaceAfterEscape'])) {
                            foreach ($fieldInfo['replaceAfterEscape'] as $number => $values) {
                                foreach ($values as $find => $replace) {
                                    if ($find === 'boost') {
                                        continue;
                                    }
                                    $boost = $values['boost'] ?? '';
                                    $queryTerm = str_replace($find, $replace, $queryTerm);

                                    if ($boost) {
                                        $pos = strpos($queryTerm, $replace);
                                        $strLength = strlen($replace);
                                        $docId = substr($queryTerm, ($pos + $strLength), 10);
                                        $queryTerm = str_replace($replace . $docId, $replace . $docId . '^' . $boost, $queryTerm);
                                    }
                                }
                            }
                        }

                        $queryTerms[$key] = $queryTerm;
                    }

                    $queryPart = $magicFieldPrefix . vsprintf($queryFormat, $queryTerms);
                } elseif ((int)$fieldInfo['noescape'] === 1) {
                    $queryPart = $magicFieldPrefix . vsprintf($queryFormat, $queryTerms);
                } else {
                    $queryPart = $magicFieldPrefix . $this->query->getHelper()->escapePhrase(vsprintf($queryFormat, $queryTerms));
                }

                if ($queryPart !== '' && $queryPart !== '0') {
                    $queryComponents[$fieldID] = $queryPart;
                }
            }
        }

        // Ask for all results if there is no query.
        if ($queryComponents === []) {
            $queryComponents[] = $this->settings['defaultQuery'];
        }

        return $queryComponents;
    }

    /**
     * Adds information about the selected items for a given facet to $activeFacets.
     *
     * @param string $facetID        ID of the facet to set
     * @param array  $facetSelection array of selected items for the facet
     */
    protected function setActiveFacetSelectionForID(array &$activeFacets, string $facetID, array $facetSelection): void
    {
        $facetQueries = [];
        $facetConfig = $this->getFacetConfig($facetID);

        // FORK-ABWEICHUNG: Modifier "not" bei Facettenauswahl
        // Im Original wird array_keys($facetSelection) verwendet und die Werte verworfen.
        // Der Fork iteriert stattdessen mit $facetTerm => $facetStatus, wobei $facetStatus
        // der Wert des Facetten-Eintrags ist (z.B. "not" fuer Negierung).
        // Wenn der Wert "not" ist, wird ein 'modifier'-Flag gesetzt, das in addFacetFilters()
        // ausgewertet wird, um den Facettenfilter mit NOT zu negieren.
        // Im Original gibt es keine Moeglichkeit, Facetten zu negieren.
        foreach (array_keys($facetSelection) as $facetTerm => $facetStatus) {
            $facetInfo = [
                'id' => $facetID,
                'config' => $facetConfig,
                'term' => $facetStatus,
                'query' => $this->getFacetQuery($facetConfig, $facetStatus),
            ];
            if ($facetSelection[$facetStatus] === 'not') {
                $facetInfo['modifier'] = 'not';
            }
            $facetQueries[$facetStatus] = $facetInfo;
        }

        if ($facetQueries !== []) {
            $activeFacets[$facetID] = $facetQueries;
        }
    }

    protected function setConnection(mixed $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Sets up the fields to be fetched by the query.
     *
     * @param array $arguments request arguments
     */
    protected function setFields(array $arguments): void
    {
        $fieldsConfig = SettingsUtility::getMergedSettings('dataFields', $this->settings, $this->getAction());
        $fields = [];

        // Use field list from query parameters or from defaults.
        if (array_key_exists('data-fields', $arguments) && $arguments['data-fields']) {
            $fields = explode(',', (string)$arguments['data-fields']);
        } elseif (array_key_exists('default', $arguments) && $fieldsConfig['default']) {
            $fields = array_values($fieldsConfig['default']);
        }

        // If allowed fields are configured, keep only those.
        if (array_key_exists('allow', $fieldsConfig) && $fieldsConfig['allow']) {
            $allowedFields = $fieldsConfig['allow'];
        }

        if (isset($allowedFields)) {
            $fields = array_intersect($fields, $allowedFields);
        }

        // If disallowed fields are configured, remove those.
        if (array_key_exists('disallow', $fieldsConfig) && $fieldsConfig['disallow']) {
            $disallowedFields = $fieldsConfig['disallow'];
        }

        if (isset($disallowedFields)) {
            $fields = array_diff($fields, $disallowedFields);
        }

        // Only set fields of the query if there is a result. Otherwise use the default setting.
        if ($fields !== []) {
            $this->query->setFields($fields);
        }
    }

    /**
     * Sets up the range of documents to be fetches by $query.
     *
     * @param array $arguments request arguments
     */
    protected function setRange(array $arguments): void
    {
        $this->query->setStart($this->getOffset($arguments));
        $this->query->setRows($this->getCount($arguments));

        // FORK-ABWEICHUNG: Paginierungsoptionen im Template bereitstellen
        // Der Fork ruft hier addResultCountOptionsToTemplate() auf, damit die
        // Auswahlmoeglichkeiten fuer die Anzahl der Ergebnisse pro Seite
        // (z.B. 10, 25, 50, 100) im Template zur Verfuegung stehen.
        // Im Original wird dieser Aufruf nur in createQueryForArguments() ueber
        // eine separate Stelle gemacht. Der Fork stellt sicher, dass die
        // Paginierungsoptionen immer beim Setzen des Bereichs verfuegbar sind.
        $this->addResultCountOptionsToTemplate($arguments);
    }

    /**
     * Sets up $query’s sort order from URL arguments or the TypoScript default.
     *
     * @param array $arguments request arguments
     */
    protected function setSortOrder(array $arguments): void
    {
        $sortString = '';
        if (!empty($arguments['sort'])) {
            $sortString = $arguments['sort'];
        } elseif (!empty($this->settings['sort'])) {
            foreach ($this->settings['sort'] as $sortSetting) {
                if ($sortSetting['id'] === 'default') {
                    $sortString = $sortSetting['sortCriteria'];
                    break;
                }
            }
        }

        $this->addSortStringForQuery($sortString);
        $this->addSortOrdersToTemplate($arguments);
    }

    /**
     * Returns the facet/filter key for the given $facetID.
     */
    protected function tagForFacet(string $facetID): string
    {
        return 'facet-' . $facetID;
    }

    /*
     * Set configured main query operator. Defaults to 'AND'.
     */
    private function addDefaultQueryOperator(): void
    {
        if (isset($this->settings['defaultQueryOperator'])) {
            $defaultQueryOperator = $this->settings['defaultQueryOperator'];
            $this->query->setQueryDefaultOperator($defaultQueryOperator);
        }
    }

    private function testConnection(): void
    {
        $ping = $this->connection->createPing();
        $this->connection->ping($ping);
    }
}
