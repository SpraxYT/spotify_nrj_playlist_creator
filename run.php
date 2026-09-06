<?php

declare(strict_types=1);

/**
 * Point d’entrée CLI / HTTP (cron aaPanel).
 *
 * CLI  : php run.php [--backfill]
 * HTTP : /run.php?key=CRON_SECRET[&backfill=1]
 *
 * Cron recommandé : * * * * * (chaque minute).
 * Pendant une bannière pub ICY (adw_ad), la musique joue déjà : on bascule
 * aussitôt sur radio-api / miroir et on ajoute le titre live à Spotify.
 */

require __DIR__ . '/bootstrap.php';

$root = __DIR__;
$isCli = PHP_SAPI === 'cli';

/** Limite d’ajouts Spotify pour la passe miroir (hors live). */
const MAX_ADDS_PER_RUN = 18;

/** Nombre de titres miroir/locaux les plus récents à re-vérifier. */
const RECENT_HISTORY_CHECK = 12;

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
    // 1) Titre live (ICY si StreamTitle réel ; sinon radio-api / miroir pendant pub).
    $live = $nrj->fetchCurrentSong();
    if ($live !== null) {
        if ($history->remember($live)) {
            $logger->info('Historique local : nouveau titre live (' . $history->count() . ').');
        }
        // Toujours tenter Spotify pour le live — indépendamment du miroir.
        add_live_to_spotify($live, $spotify, $logger);
    } else {
        $logger->info('Aucun titre live résolu (pub ICY sans repli / pause / promo).');
    }

    // 2) Miroir distant → fusion locale.
    $remote = [];
    try {
        $remote = $nrj->fetchRecentSongs();
        $history->mergeRemote($remote);
        $logger->info('Sync miroir : ' . count($remote) . ' titre(s) musicaux récupérés.');
    } catch (NrjClientError $e) {
        $logger->info('Sync miroir indisponible : ' . $e->getMessage());
    }

    // 3) Compléter les trous récents (newest-first), hors doublon du live déjà tenté.
    $batch = [];
    $recentLocal = array_slice($history->songs(), 0, RECENT_HISTORY_CHECK);
    $batch = merge_songs_newest_first($batch, $recentLocal);
    $batch = merge_songs_newest_first($batch, $remote);

    if ($live !== null) {
        $batch = array_values(array_filter(
            $batch,
            static fn(NrjSong $s): bool => !songs_match($s, $live)
        ));
    }

    if ($batch === []) {
        $logger->info('Sync miroir : rien de nouveau à ajouter.');
        return;
    }

    $logger->info(
        'Sync miroir (newest-first) : ' . count($batch) . ' titre(s) à examiner, '
        . 'plafond ' . MAX_ADDS_PER_RUN . '.'
    );
    sync_pending($batch, $spotify, $logger, MAX_ADDS_PER_RUN, 'Sync miroir');
}

/**
 * Force l’ajout Spotify du titre en cours (source live).
 */
function add_live_to_spotify(NrjSong $song, SpotifyClient $spotify, Logger $logger): void
{
    if (NrjClient::isJunk($song->artist, $song->title)) {
        $logger->info('Ajout live ignoré (promo) : ' . $song->display());
        return;
    }

    if ($spotify->isHandledSuccessfully($song->songId, $song->artist, $song->title)) {
        $logger->info(
            'Ajout live : déjà en playlist / traité — ' . $song->display()
            . ' [' . $song->songId . ']'
        );
        return;
    }

    $logger->info('Ajout live : ' . $song->display() . ' [' . $song->songId . ']');
    $status = $spotify->addTrack($song->songId, $song->artist, $song->title);
    $logger->info('Ajout live résultat=' . $status . ' — ' . $song->display());
}

function songs_match(NrjSong $a, NrjSong $b): bool
{
    if ($a->songId === $b->songId) {
        return true;
    }
    return str_lower($a->artist) === str_lower($b->artist)
        && str_lower($a->title) === str_lower($b->title);
}

/**
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
 * @param list<NrjSong> $songs plus récents en premier
 */
function sync_pending(
    array $songs,
    SpotifyClient $spotify,
    Logger $logger,
    int $maxAdds,
    string $label = 'Sync'
): void {
    $counts = ['added' => 0, 'already' => 0, 'not_found' => 0, 'skipped' => 0, 'junk' => 0];
    $adds = 0;

    foreach ($songs as $song) {
        if (NrjClient::isJunk($song->artist, $song->title)) {
            $counts['junk']++;
            continue;
        }

        // Ne skip que si vraiment ajouté / URI déjà en playlist — pas un vieux not_found.
        if ($spotify->isHandledSuccessfully($song->songId, $song->artist, $song->title)) {
            $counts['skipped']++;
            continue;
        }

        if ($adds >= $maxAdds) {
            $logger->info(
                $label . ' : plafond (' . $maxAdds . ' ajouts) — reste au prochain cron.'
            );
            break;
        }

        $logger->info($label . ' ajout : ' . $song->display() . ' [' . $song->songId . ']');
        $status = $spotify->addTrack($song->songId, $song->artist, $song->title);
        $counts[$status] = ($counts[$status] ?? 0) + 1;
        if ($status === 'added') {
            $adds++;
        }
        usleep(350000);
    }

    $logger->info(sprintf(
        '%s terminée — ajoutés=%d, déjà=%d, introuvables=%d, skip=%d, promos=%d.',
        $label,
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

    $logger->info('Backfill — ' . count($songs) . ' titre(s) (newest-first).');
    sync_pending($songs, $spotify, $logger, 50, 'Backfill');
}
