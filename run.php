<?php

declare(strict_types=1);

/**
 * Point d’entrée CLI / HTTP (cron aaPanel).
 *
 * CLI  : php run.php [--backfill]
 * HTTP : /run.php?key=CRON_SECRET[&backfill=1]
 */

require __DIR__ . '/bootstrap.php';

$root = __DIR__;
$isCli = PHP_SAPI === 'cli';

try {
    $config = Config::load($root);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    if (!$isCli) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
        echo 'ERROR: ' . $e->getMessage() . "\n";
    }
    exit(1);
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string) ($_GET['key'] ?? '');
    $secret = $config->string('CRON_SECRET');
    if ($secret === '' || !hash_equals($secret, $key)) {
        http_response_code(403);
        echo "FORBIDDEN\n";
        exit(1);
    }
}

$backfill = false;
if ($isCli) {
    global $argv;
    $backfill = in_array('--backfill', $argv ?? [], true);
} else {
    $backfill = isset($_GET['backfill']) && (string) $_GET['backfill'] !== '0';
}

$logger = new Logger($root . '/data/run.log', true);
$cache = new Cache($root . '/data/cache.json');
$webradioId = $config->string('NRJ_WEBRADIO_ID') ?: '158';

try {
    $spotify = new SpotifyClient(
        $config->string('SPOTIFY_CLIENT_ID'),
        $config->string('SPOTIFY_CLIENT_SECRET'),
        $config->string('SPOTIFY_REDIRECT_URI'),
        $config->string('SPOTIFY_PLAYLIST_ID'),
        $root . '/data/token.json',
        $cache,
        $logger,
    );

    $nrj = new NrjClient($webradioId, $logger);

    if ($backfill) {
        run_backfill($nrj, $spotify, $logger);
    } else {
        process_once($nrj, $spotify, $logger);
    }

    echo "OK\n";
    exit(0);
} catch (NrjClientError | SpotifyClientError $e) {
    $logger->error($e->getMessage());
    if (!$isCli) {
        http_response_code(500);
    }
    echo "ERROR\n";
    exit(1);
} catch (Throwable $e) {
    $logger->error('Erreur inattendue : ' . $e->getMessage());
    if (!$isCli) {
        http_response_code(500);
    }
    echo "ERROR\n";
    exit(1);
}

function process_once(NrjClient $nrj, SpotifyClient $spotify, Logger $logger): void
{
    $song = $nrj->fetchCurrentSong();
    if ($song === null) {
        $logger->info('Aucun titre valide en cours (pub / pause).');
        return;
    }

    if ($spotify->hasSeenNrjSong($song->songId)) {
        $logger->info('Toujours en on-air (déjà traité) : ' . $song->display());
        return;
    }

    $logger->info('Nouveau titre NRJ : ' . $song->display() . ' (id=' . $song->songId . ')');
    $status = $spotify->addTrack($song->songId, $song->artist, $song->title);
    $logger->info('Résultat : ' . $status);
}

function run_backfill(NrjClient $nrj, SpotifyClient $spotify, Logger $logger): void
{
    $songs = $nrj->fetchRecentSongs();
    $logger->info('Backfill — ' . count($songs) . ' titre(s) unique(s) dans l’historique NRJ.');

    $pending = array_reverse($songs);
    $counts = ['added' => 0, 'already' => 0, 'not_found' => 0, 'skipped' => 0];

    foreach ($pending as $song) {
        if ($spotify->hasSeenNrjSong($song->songId)) {
            $counts['skipped']++;
            $logger->info('Déjà traité : ' . $song->display());
            continue;
        }

        $logger->info('Backfill : ' . $song->display() . ' (id=' . $song->songId . ')');
        $status = $spotify->addTrack($song->songId, $song->artist, $song->title);
        $counts[$status] = ($counts[$status] ?? 0) + 1;
        usleep(350000);
    }

    $logger->info(sprintf(
        'Backfill terminé — ajoutés=%d, déjà présents=%d, introuvables=%d, ignorés(cache)=%d.',
        $counts['added'],
        $counts['already'],
        $counts['not_found'],
        $counts['skipped']
    ));
}
