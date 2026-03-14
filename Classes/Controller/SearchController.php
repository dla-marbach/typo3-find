<?php

namespace Subugoe\Find\Controller;

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
use Subugoe\Find\Service\ServiceProviderInterface;
use Subugoe\Find\Utility\ArrayUtility;
use Subugoe\Find\Utility\FrontendUtility;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\PageTitle\PageTitleProviderInterface;
use TYPO3\CMS\Core\Utility\ArrayUtility as CoreArrayUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class SearchController extends ActionController
{
    protected array $requestArguments = [];

    public function __construct(private readonly AssetCollector $assetCollector, private readonly ServiceProviderInterface $searchProvider, private readonly PageTitleProviderInterface $pageTitleProvider) {}

    /**
     * @throws \JsonException
     */
    public function detailAction(string $id): ResponseInterface
    {
        $arguments = $this->searchProvider->getRequestArguments();
        $detail = $this->searchProvider->getDocumentById($id);
        if ($this->request->hasArgument('underlyingQuery')) {
            $underlyingQueryInfo = $this->request->getArgument('underlyingQuery');
            $underlyingQueryScriptTagContent = FrontendUtility::addQueryInformationAsJavaScript(
                $underlyingQueryInfo['q'],
                $this->settings,
                (int)$underlyingQueryInfo['position'],
                $arguments
            );

            $this->assetCollector->addInlineJavaScript('underlyingQueryVar', sprintf('const underlyingQuery = %s;', $underlyingQueryScriptTagContent), ['type' => 'text/javascript'], ['priority' => true]);

        }

        $this->addStandardAssignments();

        $this->view->assignMultiple($detail);
        $this->view->assignMultiple([
            'underlyingQuery' => $underlyingQueryScriptTagContent ?? '',
            'arguments' => $arguments,
            'config' => $this->searchProvider->getConfiguration(),
        ]);

        return $this->htmlResponse();
    }

    /**
     * @throws \JsonException
     */
    public function indexAction(): ResponseInterface
    {
        // FORK-ABWEICHUNG: Der qParam-Redirect stellt sicher, dass die Request-Parameter bei der
        // Suche erhalten bleiben. Im Original (subugoe/typo3-find) existiert dieses Feature nicht.
        // Es wird benötigt, damit die Ajax-Facetten-Funktionalität des Forks korrekt funktioniert,
        // da die Request-Parameter für die Facetten-Abfragen bewahrt werden müssen.
        if (!array_key_exists('qParam', $this->requestArguments)) {
            $params = ['qParam' => '1'];
            return $this->redirect('index', null, null, array_merge($this->requestArguments, $params));
        }

        if (array_key_exists('id', $this->requestArguments)) {
            // FORK-ABWEICHUNG: Im Original wird hier ein ForwardResponse verwendet.
            // Der Fork nutzt stattdessen einen Redirect, um sicherzustellen, dass die
            // URL im Browser korrekt aktualisiert wird, was für die Detail-Ansicht
            // mit den Fork-spezifischen Features (z.B. Einzeltreffer-Weiterleitung) nötig ist.
            return $this->redirect('detail', null, null, $this->requestArguments);
        }

        $this->searchProvider->setCounter();

        $underlyingQueryScriptTagContent = FrontendUtility::addQueryInformationAsJavaScript(
            $this->searchProvider->getRequestArguments()['q'] ?? [],
            $this->settings,
            null,
            $this->searchProvider->getRequestArguments()
        );

        $this->assetCollector->addInlineJavaScript('underlyingQueryVar', sprintf('const underlyingQuery = %s;', $underlyingQueryScriptTagContent), ['type' => 'text/javascript'], ['priority' => true]);

        $this->addStandardAssignments();
        $defaultQuery = $this->searchProvider->getDefaultQuery();

        // FORK-ABWEICHUNG: Automatische Weiterleitung zur Detail-Ansicht, wenn die Suche
        // nur ein einziges Ergebnis liefert. Diese Funktion existiert im Original nicht.
        // Sie kann über die TypoScript-Einstellungen 'redirectAllOneHitToDetail' (global)
        // oder 'redirectToDetail' (pro queryField) konfiguriert werden.
        if ($defaultQuery['results']->getNumFound() === 1) {
            if (!empty($this->settings['redirectAllOneHitToDetail'])) {
                $docId = $defaultQuery['results']->getData()['response']['docs'][0]['id'];
                return $this->redirect('detail', null, null, ['id' => $docId]);
            }

            $redirectQueries = [];
            foreach ($this->settings['queryFields'] as $querySettings) {
                if (!empty($querySettings['redirectToDetail'])) {
                    $redirectQueries[$querySettings['id']] = 1;
                }
            }

            if (isset($this->requestArguments['q']) && is_array($this->requestArguments['q'])) {
                foreach ($this->requestArguments['q'] as $queryId => $queryTerm) {
                    if (array_key_exists($queryId, $redirectQueries)) {
                        $docId = $defaultQuery['results']->getData()['response']['docs'][0]['id'];
                        return $this->redirect('detail', null, null, ['id' => $docId]);
                    }
                }
            }
        }

        $viewValues = [
            'underlyingQuery' => $underlyingQueryScriptTagContent,
            'arguments' => $this->searchProvider->getRequestArguments(),
            'config' => $this->searchProvider->getConfiguration(),
        ];

        CoreArrayUtility::mergeRecursiveWithOverrule($viewValues, $defaultQuery);
        $this->view->assignMultiple($viewValues);

        return $this->htmlResponse();
    }

    /**
     * Initialisation and setup.
     */
    protected function initializeAction(): void
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
    public function suggestAction(): ResponseInterface
    {
        $results = $this->searchProvider->suggestQuery($this->searchProvider->getRequestArguments());
        $this->view->assign('suggestions', $results);

        return $this->htmlResponse();
    }

    /**
     * Assigns standard variables to the view.
     */
    protected function addStandardAssignments(): void
    {
        $this->searchProvider->setConfigurationValue('extendedSearch', $this->searchProvider->isExtendedSearch());
        $this->searchProvider->setConfigurationValue(
            'uid',
            $this->request->getAttribute('currentContentObject')->data['uid']
        );
        $this->searchProvider->setConfigurationValue('prefixID', 'tx_find_find');
        $this->searchProvider->setConfigurationValue('pageTitle', $this->pageTitleProvider->getTitle());
    }

    protected function initializeConnection(string $activeConnection): void
    {
        $this->searchProvider->setConnectionName($activeConnection);
        $this->searchProvider->setSettings($this->settings);

        $this->searchProvider->connect();
    }
}
