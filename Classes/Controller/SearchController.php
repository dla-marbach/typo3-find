<?php

namespace Dla\Find\Controller;

/* * *************************************************************
 *  Copyright notice
 *
 *  (c) 2013
 *      Ingo Pfennigstorf <pfennigstorf@sub-goettingen.de>
 *      Sven-S. Porst
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

use Psr\Http\Message\ResponseInterface;
use Dla\Find\Service\ServiceProviderInterface;
use Dla\Find\Utility\ArrayUtility;
use Dla\Find\Utility\FrontendUtility;
use TYPO3\CMS\Core\Log\LogManagerInterface;
use TYPO3\CMS\Core\Utility\ArrayUtility as CoreArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class SearchController extends ActionController
{
    protected array $requestArguments = [];

    protected ?object $searchProvider = null;

    private \Psr\Log\LoggerInterface $logger;

    public function __construct(LogManagerInterface $logManager)
    {
        $this->logger = $logManager->getLogger('find');
    }

    /**
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     */
    public function detailAction(string $id): ResponseInterface
    {
        $arguments = $this->searchProvider->getRequestArguments();
        $detail = $this->searchProvider->getDocumentById($id);

        if ($this->request->hasArgument('underlyingQuery')) {
            $underlyingQueryInfo = $this->request->getArgument('underlyingQuery');
            $assetCollector = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\AssetCollector::class);
            $assetCollector->addInlineJavaScript(
                'my_identifier',
                FrontendUtility::addQueryInformationAsJavaScript(
                    $underlyingQueryInfo['q'] ?? [],
                    $this->settings,
                    (int) $underlyingQueryInfo['position'],
                    $arguments
                ),
                [],
                ['priority' => true]
            );
//            $this->response->addAdditionalHeaderData(
//                FrontendUtility::addQueryInformationAsJavaScript(
//                    $underlyingQueryInfo['q'],
//                    $this->settings,
//                    (int) $underlyingQueryInfo['position'],
//                    $arguments
//                )
//            );
        }

        $this->addStandardAssignments();

        $this->view->assignMultiple($detail);
        $this->view->assignMultiple([
            'arguments' => $arguments,
            'config' => $this->searchProvider->getConfiguration(),
        ]);

        return $this->htmlResponse();
    }

    /**
     * Index Action.
     */
    public function indexAction(): ResponseInterface
    {
        if(!array_key_exists('qParam', $this->requestArguments)) {
            $params = array('qParam' => '1');
            return $this->redirect('index', NULL, NULL, array_merge($this->requestArguments, $params));
        }

        if (array_key_exists('id', $this->requestArguments)) {
            return $this->redirect('detail', NULL, NULL, $this->requestArguments);
        } else {
            $this->searchProvider->setCounter();

            $assetCollector = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\AssetCollector::class);
            $assetCollector->addInlineJavaScript(
                'my_identifier',
                FrontendUtility::addQueryInformationAsJavaScript(
                    $this->searchProvider->getRequestArguments()['q'] ?? [],
                    $this->settings,
                    null,
                    $this->searchProvider->getRequestArguments()
                ),
                [],
                ['priority' => true]
            );

            $this->addStandardAssignments();
            $defaultQuery = $this->searchProvider->getDefaultQuery();

            // redirect to detail if only one item found and search is configured to redirect
            if ($defaultQuery['results']->getNumFound() === 1) {
                $redirectQueries = [];
                if ($this->settings['redirectAllOneHitToDetail']) {
                    $docId = $defaultQuery['results']->getData()['response']['docs'][0]['id'];
                    return $this->redirect('detail', NULL, NULL, ['id' => $docId]);
                } else {
                    foreach ($this->settings['queryFields'] as $querySettings) {
                        if ($querySettings['redirectToDetail']) {
                            $redirectQueries[$querySettings['id']] = 1;
                        }
                    }

                    foreach ($this->requestArguments['q'] as $queryId => $queryTerm) {
                        if (array_key_exists($queryId, $redirectQueries)) {
                            $docId = $defaultQuery['results']->getData()['response']['docs'][0]['id'];
                            return $this->redirect('detail', NULL, NULL, ['id' => $docId]);
                        }
                    }
                }
            }

            $viewValues = [
                'arguments' => $this->searchProvider->getRequestArguments(),
                'config' => $this->searchProvider->getConfiguration(),
            ];

            CoreArrayUtility::mergeRecursiveWithOverrule($viewValues, $defaultQuery);
            $this->view->assignMultiple($viewValues);
        }
        return $this->htmlResponse();
    }

    /**
     * Initialisation and setup.
     */
    protected function initializeAction()
    {
        ksort($this->settings['queryFields']);

        $this->initializeConnection($this->settings['activeConnection']);

        $this->requestArguments = $this->request->getArguments();
        $this->requestArguments = ArrayUtility::cleanArgumentsArray($this->requestArguments);

        $this->searchProvider->setRequestArguments($this->requestArguments);
        $this->searchProvider->setAction($this->request->getControllerActionName());
        $this->searchProvider->setControllerExtensionKey($this->request->getControllerExtensionKey());
    }

    /**
     * Suggest/Autocomplete action.
     */
    public function suggestAction()
    {
        $results = $this->searchProvider->suggestQuery($this->searchProvider->getRequestArguments());
        $this->view->assign('suggestions', $results);
    }

    /**
     * Assigns standard variables to the view.
     */
    protected function addStandardAssignments()
    {
        $this->searchProvider->setConfigurationValue('extendedSearch', $this->searchProvider->isExtendedSearch());
        $this->searchProvider->setConfigurationValue(
            'uid',
            $this->configurationManager->getContentObject()->data['uid']
        );
        $this->searchProvider->setConfigurationValue('prefixID', 'tx_find_find');
        $this->searchProvider->setConfigurationValue('pageTitle', $GLOBALS['TSFE']->page['title']);
    }

    /**
     * @param string $activeConnection
     */
    protected function initializeConnection($activeConnection)
    {
        $connectionConfiguration = $this->settings['connections'][$activeConnection];

        /* @var ServiceProviderInterface $searchProvider */
        $this->searchProvider = GeneralUtility::makeInstance($connectionConfiguration['provider'], $activeConnection,
            $this->settings);
        $this->searchProvider->connect();
    }
}
