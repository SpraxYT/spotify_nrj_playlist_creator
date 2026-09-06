<?php

declare(strict_types=1);

/**
 * Page web : historique NRJ (local + sync miroir) → playlist Spotify.
 * Protégée par CRON_SECRET (session après saisie du secret).
 */

require __DIR__ . '/bootstrap.php';

WebAuth::startSession();

header('Content-Type: text/html; charset=utf-8');

$error = null;
$info = null;
$songs = null;
/** @var array{added:int,already:int,not_found:int,skipped:int,junk:int}|null $result */
$result = null;
$details = [];

try {
    $config = Config::load(__DIR__);
} catch (Throwable $e) {
    http_response_code(500);
    echo history_layout('Erreur', '<p class="err">' . history_h($e->getMessage()) . '</p>');
    exit(1);
}

$secret = $config->string('CRON_SECRET');
$historyStore = new NrjHistoryStore(__DIR__ . '/data/nrj_history.json');

if (isset($_POST['logout'])) {
    WebAuth::logout();
    header('Location: history.php');
    exit(0);
}

$authed = WebAuth::guard($secret);

if (!$authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['key'])) {
    $error = 'Secret incorrect.';
}

if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        $nrj = new NrjClient(
            $config->string('NRJ_WEBRADIO_ID') ?: '158',
            null,
            $config->string('NRJ_STREAM_URL') ?: null,
        );

        if ($action === 'fetch' || $action === 'poll_current' || $action === 'add' || $action === 'create_playlist') {
            // Toujours tenter le miroir pour rafraîchir l’historique local (liste complète)
            if ($action === 'fetch' || $action === 'add') {
                try {
                    $remote = $nrj->fetchRecentSongs();
                    $merged = $historyStore->mergeRemote($remote);
                    // Enrichir avec le titre ICY en cours s’il n’est pas déjà dans le miroir
                    try {
                        $current = $nrj->fetchCurrentSong();
                        if ($current !== null) {
                            $historyStore->remember($current);
                        }
                    } catch (Throwable) {
                        // ICY optionnel
                    }
                    $info = $merged . ' titre(s) musicaux du miroir (union avec l’historique local, promos filtrées). '
                        . 'Total affiché : ' . $historyStore->count() . '.';
                } catch (NrjClientError $e) {
                    if ($action === 'fetch') {
                        throw $e;
                    }
                    $info = 'Miroir distant indisponible — utilisation de l’historique local. '
                        . $e->getMessage();
                }
            }
        }

        if ($action === 'create_playlist') {
            $logger = new Logger(__DIR__ . '/data/run.log', false);
            $cache = new Cache(__DIR__ . '/data/cache.json');
            $spotify = new SpotifyClient(
                $config->string('SPOTIFY_CLIENT_ID'),
                $config->string('SPOTIFY_CLIENT_SECRET'),
                $config->string('SPOTIFY_REDIRECT_URI'),
                $config->string('SPOTIFY_PLAYLIST_ID'),
                __DIR__ . '/data/token.json',
                $cache,
                $logger,
                false,
                __DIR__,
            );
            $created = $spotify->createPlaylist(
                'NRJ Bot ' . gmdate('Y-m-d H:i'),
                'Créée automatiquement par nrjbot',
                true
            );
            SpotifyClient::savePlaylistIdOverride(__DIR__, $created['id'], $created['name']);
            $info = 'Playlist créée sous votre compte : « ' . $created['name'] . ' » (id='
                . $created['id'] . '). Enregistrée dans data/playlist_id.json.';
        }

        if ($action === 'poll_current') {
            $current = $nrj->fetchCurrentSong();
            if ($current === null) {
                $info = 'Aucun titre musical en cours (pub, jingle ou métadonnée vide).';
            } elseif ($historyStore->remember($current)) {
                $info = 'Titre en cours enregistré : ' . $current->display();
            } else {
                $info = 'Titre en cours déjà connu : ' . $current->display();
            }
        }

        if ($action === 'add') {
            $songsList = $historyStore->songs();
            if ($songsList === []) {
                throw new RuntimeException(
                    'Aucun titre musical à ajouter. Cliquez « Récupérer l’historique NRJ » '
                    . 'ou attendez le cron run.php.'
                );
            }

            $logger = new Logger(__DIR__ . '/data/run.log', false);
            $cache = new Cache(__DIR__ . '/data/cache.json');
            $spotify = new SpotifyClient(
                $config->string('SPOTIFY_CLIENT_ID'),
                $config->string('SPOTIFY_CLIENT_SECRET'),
                $config->string('SPOTIFY_REDIRECT_URI'),
                $config->string('SPOTIFY_PLAYLIST_ID'),
                __DIR__ . '/data/token.json',
                $cache,
                $logger,
            );

            $pending = array_reverse($songsList);
            $counts = ['added' => 0, 'already' => 0, 'not_found' => 0, 'skipped' => 0, 'junk' => 0];

            foreach ($pending as $song) {
                if (NrjClient::isJunk($song->artist, $song->title)) {
                    $counts['junk']++;
                    continue;
                }
                if ($spotify->isHandledSuccessfully($song->songId, $song->artist, $song->title)) {
                    $counts['skipped']++;
                    $details[] = ['label' => $song->display(), 'status' => 'déjà traité'];
                    continue;
                }

                $status = $spotify->addTrack($song->songId, $song->artist, $song->title);
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                $label = match ($status) {
                    'added'     => 'ajouté',
                    'already'   => 'déjà dans la playlist',
                    'not_found' => 'introuvable',
                    default     => $status,
                };
                $details[] = ['label' => $song->display(), 'status' => $label];
                usleep(350000);
            }

            $result = $counts;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($authed) {
    $songs = $historyStore->songs();
}

ob_start();

if (!$authed) {
    ?>
    <h1>Historique NRJ</h1>
    <p class="muted">Entrez le secret <code>CRON_SECRET</code> pour continuer.</p>
    <?php if ($error): ?><p class="err"><?= history_h($error) ?></p><?php endif; ?>
    <form method="post" class="box">
        <label for="key">Secret</label>
        <input type="password" id="key" name="key" required autocomplete="current-password">
        <button type="submit">Se connecter</button>
    </form>
    <?php
} else {
    ?>
    <div class="top">
        <h1>Historique NRJ</h1>
        <form method="post"><button type="submit" name="logout" value="1" class="linkish">Déconnexion</button></form>
    </div>
    <p class="muted">
        Récupère l’historique via le miroir NRJ (hors Cloudflare), filtre les promos/jingles,
        puis ajoute les titres manquants à votre playlist Spotify.
        Playlist configurée : <code><?= history_h($config->string('SPOTIFY_PLAYLIST_ID')) ?></code>
        — elle doit appartenir au compte autorisé via <a href="auth.php">auth.php</a>.
    </p>
    <?php if ($error): ?>
        <div class="box errbox">
            <strong>Erreur Spotify / NRJ</strong>
            <p class="err"><?= history_h($error) ?></p>
            <?php if (str_contains(strtolower($error), '403') || str_contains(strtolower($error), 'forbidden')): ?>
                <ol class="help">
                    <li>Dashboard Spotify → votre app → <strong>User Management</strong> → Add user
                        (e-mail du compte Spotify utilisé pour auth.php) si l’app est en
                        <em>Development mode</em>.</li>
                    <li>Vérifiez que <code>SPOTIFY_PLAYLIST_ID</code> est une playlist
                        <strong>créée par ce même compte</strong>
                        (ex. <code>4DCkQ853je6ajt28tF6bef</code>).</li>
                    <li>Ré-ouvrez <a href="auth.php">auth.php</a> après avoir ajouté l’utilisateur.</li>
                    <li>Consultez <code>data/run.log</code> : lignes « Spotify connecté » et
                        « Playlist … propriétaire » pour comparer les IDs.</li>
                </ol>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($info): ?><p class="okmsg"><?= history_h($info) ?></p><?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="box ok">
            <strong>Résultat</strong>
            <ul class="counts">
                <li>Ajoutés : <?= (int) $result['added'] ?></li>
                <li>Déjà présents : <?= (int) $result['already'] ?></li>
                <li>Introuvables : <?= (int) $result['not_found'] ?></li>
                <li>Ignorés (cache) : <?= (int) $result['skipped'] ?></li>
                <li>Promos filtrées : <?= (int) ($result['junk'] ?? 0) ?></li>
            </ul>
        </div>
        <?php if ($details !== []): ?>
            <details class="box">
                <summary>Détail par titre</summary>
                <ul class="list">
                    <?php foreach ($details as $d): ?>
                        <li><span><?= history_h($d['label']) ?></span> <em><?= history_h($d['status']) ?></em></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" class="actions">
        <input type="hidden" name="action" value="fetch">
        <button type="submit">Récupérer l’historique NRJ (miroir)</button>
    </form>
    <form method="post" class="actions">
        <input type="hidden" name="action" value="poll_current">
        <button type="submit">Capturer le titre en cours (ICY)</button>
    </form>
    <form method="post" class="actions">
        <input type="hidden" name="action" value="create_playlist">
        <button type="submit">Créer une nouvelle playlist NRJ via l’API</button>
    </form>
    <p class="muted">Diagnostic : <a href="debug.php">debug.php</a>
        (même secret) — test d’ajout d’un titre, comparaison me/owner, scopes.</p>

    <?php if (is_array($songs) && $songs !== []): ?>
        <p><strong><?= count($songs) ?></strong> titre(s) musicaux</p>
        <form method="post" class="actions">
            <input type="hidden" name="action" value="add">
            <button type="submit" class="primary">Ajouter à la playlist Spotify</button>
        </form>
        <ol class="list tracks">
            <?php foreach ($songs as $s): ?>
                <li><?= history_h($s->artist) ?> – <?= history_h($s->title) ?></li>
            <?php endforeach; ?>
        </ol>
    <?php else: ?>
        <p class="muted">Aucun titre pour l’instant. Récupérez l’historique ou attendez le cron.</p>
    <?php endif; ?>
    <?php
}

$body = ob_get_clean();
echo history_layout('Historique NRJ → Spotify', $body);
exit(0);

function history_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function history_layout(string $title, string $body): string
{
    return '<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . history_h($title) . '</title>
<style>
:root { color-scheme: light; --bg:#f6f5f2; --fg:#1a1a1a; --muted:#5c5c5c; --line:#d8d5ce; --accent:#1db954; --err:#b00020; }
*{box-sizing:border-box}
body{margin:0;font:16px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--fg)}
main{max-width:40rem;margin:0 auto;padding:2rem 1.25rem 3rem}
h1{font-size:1.4rem;margin:0 0 .5rem;font-weight:650}
.muted{color:var(--muted);margin:.25rem 0 1.25rem}
.muted a{color:inherit}
.top{display:flex;align-items:baseline;justify-content:space-between;gap:1rem;margin-bottom:.25rem}
.box{background:#fff;border:1px solid var(--line);padding:1rem 1.1rem;margin:1rem 0}
.box.ok{border-color:#9fd9b3}
.box.errbox{border-color:#f0b4be;background:#fff8f8}
label{display:block;font-size:.9rem;margin-bottom:.35rem}
input[type=password]{width:100%;padding:.55rem .65rem;border:1px solid var(--line);background:#fff;font:inherit;margin-bottom:.75rem}
button{font:inherit;padding:.55rem .9rem;border:1px solid var(--line);background:#fff;cursor:pointer}
button.primary{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:600}
button.linkish{border:0;background:transparent;color:var(--muted);padding:0;text-decoration:underline;cursor:pointer}
.actions{margin:1rem 0}
.list{margin:.75rem 0 0;padding-left:1.2rem}
.list li{margin:.2rem 0}
.list em{color:var(--muted);font-style:normal;font-size:.9rem}
.counts{margin:.5rem 0 0;padding-left:1.2rem}
.help{margin:.75rem 0 0;padding-left:1.2rem;color:var(--muted);font-size:.95rem}
.err{color:var(--err)}
.okmsg{color:#0b6b3a}
code{font-size:.9em}
a.nav{color:var(--muted);font-size:.9rem}
</style>
</head>
<body>
<main>
<p><a class="nav" href="index.php">← Accueil</a></p>
' . $body . '
</main>
</body>
</html>';
}
