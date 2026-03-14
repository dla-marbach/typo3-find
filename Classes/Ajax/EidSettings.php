<?php

/**
 * FORK-ABWEICHUNG: Diese gesamte Datei existiert im Original (subugoe/typo3-find) nicht.
 * Sie enthaelt die Solr-Verbindungseinstellungen fuer die Ajax-Facetten-Middleware
 * (Classes/Ajax/Facets.php). Die Werte fuer $host und $core muessen an die jeweilige
 * Solr-Installation angepasst werden.
 * Hinweis: Diese Datei wird von der DI-Registrierung in Configuration/Services.yaml
 * ausgeschlossen, da sie keine regulaere PHP-Klasse ist.
 */

$host = 'http://instant.dla-marbach.de:8983/solr/';
$core = 'opac-ng';
