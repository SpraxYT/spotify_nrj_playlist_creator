<?php

declare(strict_types=1);

/**
 * Point d’entrée CLI / HTTP (cron aaPanel).
 *
 * CLI  : php run.php [--backfill]
 * HTTP : /run.php?key=CRON_SECRET[&backfill=1]
 *
 * Cron recommandé : * * * * * (chaque minute) pour coller au direct NRJ.
 * Chaque passage : ICY (titre live) d’abord, puis historique miroir du plus
 * récent au plus ancien (max MAX_ADDS_PER_RUN ajouts Spotify).
 */

require __DIR__ . '/bootstrap.php';

$root = __DIR__;
$isCli = PHP_SAPI === 'cli';

/** Limite d’ajouts Spotify par passage cron (rate limit). */
const MAX_ADDS_PER_RUN = 18;

/** Nombre de titres locaux les plus récents à re-vérifier chaque passage. */
const RECENT_HISTORY_CHECK = 10;

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
$history = new NrjHistoryStore($root . '/data/nrj_history.json');
$webradioId = $config->string('NRJ_WEBRADIO_ID') ?: '158';
$streamUrl = $config->string('NRJ_STREAM_URL') ?: null;

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

    $nrj = new NrjClient($webradioId, $logger, $streamUrl);

    if ($backfill) {
        run_backfill($nrj, $spotify, $history, $logger);
    } else {
        process_once($nrj, $spotify, $history, $logger);
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

function process_once(
    NrjClient $nrj,
    SpotifyClient $spotify,
    NrjHistoryStore $history,
    Logger $logger
): void {
    // 1) Titre live ICY / API — prioritaire (le miroir est souvent en retard).
    $current = $nrj->fetchCurrentSong();
    if ($current !== null) {
        if ($history->remember($current)) {
            $logger->info('Historique local : nouveau titre (' . $history->count() . ').');
        }
    } else {
        $logger->info('Aucun titre ICY/API valide en cours (pub / pause / promo).');
    }

    // 2) Miroir distant → fusion locale (ordre distant : plus récent en premier).
    $remote = [];
    try {
        $remote = $nrj->fetchRecentSongs();
        $history->mergeRemote($remote);
        $logger->info('Sync historique distant : ' . count($remote) . ' titre(s) musicaux.');
    } catch (NrjClientError $e) {
        $logger->info('Historique distant indisponible : ' . $e->getMessage());
    }

    // 3) Lot Spotify : live d’abord, puis les N plus récents (miroir/local), newest-first.
    $batch = [];
    if ($current !== null) {
        $batch[] = $current;
    }

    $recentLocal = array_slice($history->songs(), 0, RECENT_HISTORY_CHECK);
    $batch = merge_songs_newest_first($batch, $recentLocal);
    $batch = merge_songs_newest_first($batch, $remote);

    if ($batch === []) {
        $logger->info('Rien à ajouter à Spotify pour ce passage.');
        return;
    }

    $logger->info(
        'File d’ajout (newest-first) : ' . count($batch) . ' titre(s), '
        . 'plafond ' . MAX_ADDS_PER_RUN . '.'
    );
    sync_pending($batch, $spotify, $logger, MAX_ADDS_PER_RUN);
}

/**
 * Fusionne des listes (plus récent en premier) sans doublons.
 *
 * @param list<NrjSong> ...$lists
 * @return list<NrjSong>
 */
function merge_songs_newest_first(array ...$lists): array
{
    $out = [];
    $seen = [];
    foreach ($lists as $list) {
        foreach ($list as $song) {
            if (!$song instanceof NrjSong) {
                continue;
            }
            $key = $song->songId . '|' . str_lower($song->artist) . '|' . str_lower($song->title);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $song;
        }
    }
    return $out;
}

/**
 * Ajoute les titres manquants à Spotify — ordre : plus récent en premier.
 *
 * @param list<NrjSong> $songs plus récents en premier
 */
function sync_pending(
    array $songs,
    SpotifyClient $spotify,
    Logger $logger,
    int $maxAdds
): void {
    $counts = ['added' => 0, 'already' => 0, 'not_found' => 0, 'skipped' => 0, 'junk' => 0];
    $adds = 0;

    foreach ($songs as $song) {
        if (NrjClient::isJunk($song->artist, $song->title)) {
            $counts['junk']++;
            continue;
        }

        if ($spotify->hasSeenNrjSong($song->songId)) {
            $counts['skipped']++;
            continue;
        }

        if ($adds >= $maxAdds) {
            $logger->info(
                'Plafond atteint (' . $maxAdds . ' ajouts / passage) — reste reporté au prochain cron.'
            );
            break;
        }

        $logger->info('Ajout Spotify : ' . $song->display() . ' (id=' . $song->songId . ')');
        $status = $spotify->addTrack($song->songId, $song->artist, $song->title);
        $counts[$status] = ($counts[$status] ?? 0) + 1;
        if ($status === 'added') {
            $adds++;
        }
        usleep(350000);
    }

    $logger->info(sprintf(
        'Sync terminée — ajoutés=%d, déjà=%d, introuvables=%d, cache=%d, promos ignorées=%d.',
        $counts['added'],
        $counts['already'],
        $counts['not_found'],
        $counts['skipped'],
        $counts['junk']
    ));
}

function run_backfill(
    NrjClient $nrj,
    SpotifyClient $spotify,
    NrjHistoryStore $history,
    Logger $logger
): void {
    $songs = $history->songs();
    if ($songs === []) {
        $logger->info('Historique local vide — tentative distant…');
        try {
            $songs = $nrj->fetchRecentSongs();
            foreach (array_reverse($songs) as $song) {
                $history->remember($song);
            }
            $songs = $history->songs();
        } catch (NrjClientError $e) {
            $logger->warning($e->getMessage());
            $current = $nrj->fetchCurrentSong();
            if ($current !== null) {
                $history->remember($current);
                $songs = [$current];
            }
        }
    }

    if ($songs === []) {
        $logger->info('Backfill : aucun titre à traiter.');
        return;
    }

    $logger->info('Backfill — ' . count($songs) . ' titre(s) dans l’historique (newest-first).');
    sync_pending($songs, $spotify, $logger, 50);
}
