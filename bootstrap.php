<?php

declare(strict_types=1);

/**
 * Charge les classes du projet. À inclure depuis la racine :
 * require __DIR__ . '/bootstrap.php';
 */

$ROOT = __DIR__;

require_once $ROOT . '/src/helpers.php';
require_once $ROOT . '/src/Config.php';
require_once $ROOT . '/src/Logger.php';
require_once $ROOT . '/src/Cache.php';
require_once $ROOT . '/src/Http.php';
require_once $ROOT . '/src/NrjClient.php';
require_once $ROOT . '/src/SpotifyClient.php';
require_once $ROOT . '/src/WebAuth.php';
