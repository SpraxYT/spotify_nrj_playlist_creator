<?php

declare(strict_types=1);

/**
 * Page web : historique local NRJ → playlist Spotify.
 * Protégée par CRON_SECRET (session après saisie du secret).
 *
 * L’historique distant nrj.fr est souvent bloqué par Cloudflare depuis un VPS :
 * les titres sont accumulés dans data/nrj_history.json à chaque passage du cron.
 */

require __DIR__ . '/bootstrap.php';

WebAuth::startSession();

header('Content-Type: text/html; charset=utf-8');

$error = null;
$info = null;
$songs = null;
$current = null;
/** @var array{added:int,already:int,not_found:int,skipped:int}|null $result */
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

        if ($action === 'poll_current') {
            $current = $nrj->fetchCurrentSong();
            if ($current === null) {
                $info = 'Aucun titre musical en cours (pub, jingle ou métadonnée vide). Réessayez dans quelques secondes.';
            } else {
                if ($historyStore->remember($current)) {
                    $info = 'Titre en cours enregistré dans l’historique local : ' . $current->display();
                } else {
                    $info = 'Titre en cours déjà en tête de l’historique : ' . $current->display();
                }
            }
        }

        if ($action === 'add') {
            $songsList = $historyStore->songs();
            if ($songsList === []) {
                throw new RuntimeException(
                    'Historique local vide. Lancez le cron run.php ou cliquez « Capturer le titre en cours ».'
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
            $counts = ['added' => 0, 'already' => 0, 'not_found' => 0, 'skipped' => 0];

            foreach ($pending as $song) {
                if ($spotify->hasSeenNrjSong($song->songId)) {
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
        L’historique distant NRJ (<code>nrj.fr</code>) est bloqué par Cloudflare depuis la plupart des VPS.
        Les titres s’accumulent dans <code>data/nrj_history.json</code> à chaque passage du cron
        <code>run.php</code> (métadonnées ICY du flux audio, hors Cloudflare).
    </p>
    <?php if ($error): ?><p class="err"><?= history_h($error) ?></p><?php endif; ?>
    <?php if ($info): ?><p class="okmsg"><?= history_h($info) ?></p><?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="box ok">
            <strong>Résultat</strong>
            <ul class="counts">
                <li>Ajoutés : <?= (int) $result['added'] ?></li>
                <li>Déjà présents : <?= (int) $result['already'] ?></li>
                <li>Introuvables : <?= (int) $result['not_found'] ?></li>
                <li>Ignorés (cache) : <?= (int) $result['skipped'] ?></li>
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
        <input type="hidden" name="action" value="poll_current">
        <button type="submit">Capturer le titre en cours (ICY / radio-api)</button>
    </form>

    <?php if (is_array($songs) && $songs !== []): ?>
        <p><strong><?= count($songs) ?></strong> titre(s) dans l’historique local</p>
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
        <p class="muted">Aucun titre local pour l’instant. Attendez le prochain cron ou capturez le titre en cours.</p>
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
