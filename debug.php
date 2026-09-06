<?php

declare(strict_types=1);

/**
 * Diagnostic Spotify (me / playlist / scopes / test ajout 1 titre).
 * Protégé par CRON_SECRET (?key=… ou session history.php).
 */

require __DIR__ . '/bootstrap.php';

WebAuth::startSession();
header('Content-Type: text/html; charset=utf-8');

$error = null;
$info = null;
$probe = null;

try {
    $config = Config::load(__DIR__);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p>Erreur config : ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    exit(1);
}

$secret = $config->string('CRON_SECRET');
$authed = WebAuth::guard($secret);

if (!$authed) {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Debug Spotify</title></head><body>';
    echo '<p>Ajoutez <code>?key=CRON_SECRET</code> à l’URL (même secret que history.php).</p>';
    echo '</body></html>';
    exit(0);
}

$root = __DIR__;
$tokenPath = $root . '/data/token.json';
$requiredScopes = explode(' ', SpotifyClient::scopes());

try {
    $logger = new Logger($root . '/data/run.log', false);
    $cache = new Cache($root . '/data/cache.json');
    $spotify = new SpotifyClient(
        $config->string('SPOTIFY_CLIENT_ID'),
        $config->string('SPOTIFY_CLIENT_SECRET'),
        $config->string('SPOTIFY_REDIRECT_URI'),
        $config->string('SPOTIFY_PLAYLIST_ID'),
        $tokenPath,
        $cache,
        $logger,
        false, // ne pas bloquer si owner mismatch (diagnostic)
        $root,
    );

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'test_add') {
            // Justin Bieber — Love Yourself (même titre que le test Python local)
            $uri = 'spotify:track:1zHlj4dQ8ZAtrayhuDDmkY';
            $raw = $spotify->addTrackUriRaw($uri);
            if ($raw['ok']) {
                $info = 'Test ajout OK — HTTP ' . $raw['status'] . ' sur ' . $raw['url']
                    . ' body=' . $raw['request_body'] . ' → ' . substr($raw['body'], 0, 200);
            } else {
                $error = 'Test ajout ÉCHEC — HTTP ' . $raw['status'] . ' ' . $raw['url']
                    . ' req=' . ($raw['request_body'] ?? '') . ' resp=' . substr($raw['body'], 0, 400);
            }
        }

        if ($action === 'create_playlist') {
            $created = $spotify->createPlaylist(
                'NRJ Bot ' . gmdate('Y-m-d H:i'),
                'Créée automatiquement par nrjbot (API /me/playlists)',
                true
            );
            SpotifyClient::savePlaylistIdOverride($root, $created['id'], $created['name']);
            $info = 'Playlist créée : « ' . $created['name'] . ' » id=' . $created['id']
                . ' — enregistrée dans data/playlist_id.json (prioritaire sur config).';
            // Reconstruire le client sur la nouvelle playlist
            $spotify = new SpotifyClient(
                $config->string('SPOTIFY_CLIENT_ID'),
                $config->string('SPOTIFY_CLIENT_SECRET'),
                $config->string('SPOTIFY_REDIRECT_URI'),
                $created['id'],
                $tokenPath,
                $cache,
                $logger,
                false,
                $root,
            );
        }
    }

    $tokens = SpotifyClient::readTokenFile($tokenPath);
    $tokenScope = (string) ($tokens['scope'] ?? '');
    $scopeList = $tokenScope !== '' ? preg_split('/\s+/', trim($tokenScope)) ?: [] : [];
    $missingScopes = array_values(array_diff($requiredScopes, $scopeList));

    $me = $spotify->currentUser();
    $pl = $spotify->playlistInfo();
    $match = $spotify->isOwnerMatch();

    $probe = [
        'client_id'       => $config->string('SPOTIFY_CLIENT_ID'),
        'playlist_id'     => $spotify->playlistId(),
        'config_playlist' => $config->string('SPOTIFY_PLAYLIST_ID'),
        'override_file'   => is_file($root . '/data/playlist_id.json'),
        'me'              => $me,
        'playlist'        => $pl,
        'owner_match'     => $match,
        'token_scope'     => $tokenScope,
        'missing_scopes'  => $missingScopes,
        'add_endpoint'    => 'POST /v1/playlists/{id}/items  body={"uris":["spotify:track:…"]}',
    ];
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function d_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Debug Spotify</title>
<style>
body{font:16px/1.5 system-ui,sans-serif;max-width:44rem;margin:0 auto;padding:1.5rem;background:#f6f5f2;color:#1a1a1a}
code,pre{font-size:.9em;background:#fff;border:1px solid #ddd;padding:.15rem .35rem}
pre{padding:.75rem;overflow:auto;white-space:pre-wrap}
.ok{color:#0b6b3a}.err{color:#b00020}.box{background:#fff;border:1px solid #d8d5ce;padding:1rem;margin:1rem 0}
button{font:inherit;padding:.5rem .85rem;cursor:pointer;margin:.25rem .25rem .25rem 0}
.verdict{font-size:1.1rem;font-weight:650}
</style>
</head>
<body>
<p><a href="history.php">← Historique</a> · <a href="auth.php">auth.php</a></p>
<h1>Debug Spotify</h1>
<p>Vérifie <code>GET /v1/me</code>, la playlist, les scopes du token, et un ajout test via
<code>POST /v1/playlists/{id}/items</code>.</p>

<?php if ($error): ?><p class="err"><?= d_h($error) ?></p><?php endif; ?>
<?php if ($info): ?><p class="ok"><?= d_h($info) ?></p><?php endif; ?>

<?php if (is_array($probe)): ?>
<div class="box">
    <p class="verdict">
        <?php if ($probe['owner_match'] === true): ?>
            <span class="ok">Verdict : me.id = owner.id — propriétaire OK.</span>
            Si l’ajout échoue encore, ce n’est pas un mismatch de compte
            (vérifier endpoint /items et scopes).
        <?php elseif ($probe['owner_match'] === false): ?>
            <span class="err">Verdict : me.id ≠ owner.id — mauvaise playlist pour ce compte.</span>
        <?php else: ?>
            Verdict : IDs incomplets — voir détails.
        <?php endif; ?>
    </p>
    <ul>
        <li>Client ID config : <code><?= d_h(substr((string) $probe['client_id'], 0, 8)) ?>…</code>
            (doit être l’app <strong>NRJ TUBE</strong>)</li>
        <li>Playlist effective : <code><?= d_h((string) $probe['playlist_id']) ?></code>
            <?php if ($probe['override_file']): ?>(override <code>data/playlist_id.json</code>)<?php endif; ?></li>
        <li>Config playlist : <code><?= d_h((string) $probe['config_playlist']) ?></code></li>
        <li>Endpoint add : <code><?= d_h((string) $probe['add_endpoint']) ?></code></li>
    </ul>
</div>

<div class="box">
    <h2>GET /v1/me</h2>
    <?php if ($probe['me']): ?>
        <pre>id: <?= d_h((string) $probe['me']['id']) ?>
display_name: <?= d_h((string) $probe['me']['display_name']) ?>
email: <?= d_h((string) ($probe['me']['email'] ?? '(non fourni par Spotify Dev Mode)')) ?></pre>
    <?php else: ?>
        <p class="err">/me indisponible</p>
    <?php endif; ?>
</div>

<div class="box">
    <h2>GET /v1/playlists/{id}</h2>
    <?php if ($probe['playlist']): ?>
        <pre>name: <?= d_h((string) $probe['playlist']['name']) ?>
id: <?= d_h((string) $probe['playlist']['id']) ?>
owner.id: <?= d_h((string) $probe['playlist']['owner_id']) ?>
owner.display_name: <?= d_h((string) $probe['playlist']['owner_name']) ?>
collaborative: <?= $probe['playlist']['collaborative'] ? 'true' : 'false' ?>
public: <?= $probe['playlist']['public'] === null ? 'null' : ($probe['playlist']['public'] ? 'true' : 'false') ?></pre>
    <?php else: ?>
        <p class="err">Métadonnées playlist indisponibles</p>
    <?php endif; ?>
</div>

<div class="box">
    <h2>Scopes du token (<code>data/token.json</code>)</h2>
    <pre><?= d_h($probe['token_scope'] !== '' ? (string) $probe['token_scope'] : '(vide)') ?></pre>
    <?php if ($probe['missing_scopes'] !== []): ?>
        <p class="err">Scopes manquants : <?= d_h(implode(', ', $probe['missing_scopes'])) ?>.
            Rouvrez <a href="auth.php">auth.php</a> (show_dialog=true).</p>
    <?php else: ?>
        <p class="ok">Scopes requis présents.</p>
    <?php endif; ?>
</div>

<form method="post" class="box">
    <input type="hidden" name="action" value="test_add">
    <p>Ajoute <em>Justin Bieber – Love Yourself</em>
        (<code>spotify:track:1zHlj4dQ8ZAtrayhuDDmkY</code>) via <code>POST …/items</code>.</p>
    <button type="submit">Test ajout 1 titre</button>
</form>

<form method="post" class="box">
    <input type="hidden" name="action" value="create_playlist">
    <p>Crée une playlist sous le compte auth (<code>POST /v1/me/playlists</code>)
        et enregistre l’id dans <code>data/playlist_id.json</code>.</p>
    <button type="submit">Créer une nouvelle playlist NRJ via l’API</button>
</form>
<?php endif; ?>

</body>
</html>
