<?php

declare(strict_types=1);

/**
 * Autorisation OAuth Spotify (une fois dans le navigateur).
 * Enregistre refresh_token dans data/token.json pour run.php / cron.
 */

require __DIR__ . '/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$projectRoot = __DIR__;
$tokenPath = $projectRoot . '/data/token.json';

try {
    $config = Config::load($projectRoot);
} catch (Throwable $e) {
    http_response_code(500);
    echo auth_page(
        'Configuration manquante',
        '<h1>Configuration manquante</h1><p class="err">'
        . auth_h($e->getMessage()) . '</p>'
    );
    exit(1);
}

$clientId = $config->string('SPOTIFY_CLIENT_ID');
$clientSecret = $config->string('SPOTIFY_CLIENT_SECRET');
$redirectUri = $config->string('SPOTIFY_REDIRECT_URI');

$error = $_GET['error'] ?? null;
$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;

if (is_string($error) && $error !== '') {
    http_response_code(400);
    echo auth_page(
        'Autorisation refusée',
        '<h1>Autorisation refusée</h1><p class="err">' . auth_h($error) . '</p>'
        . '<p><a href="auth.php">Réessayer</a></p>'
    );
    exit(1);
}

if (is_string($code) && $code !== '') {
    $expected = $_COOKIE['spotify_oauth_state'] ?? '';
    if (!is_string($state) || $state === '' || !hash_equals((string) $expected, $state)) {
        http_response_code(400);
        echo auth_page(
            'État invalide',
            '<h1>État OAuth invalide</h1>'
            . '<p class="err">Relancez l’autorisation.</p>'
            . '<p><a href="auth.php">Réessayer</a></p>'
        );
        exit(1);
    }

    try {
        SpotifyClient::assertTokenPathWritable($tokenPath);
        SpotifyClient::exchangeCode($clientId, $clientSecret, $redirectUri, $code, $tokenPath);
    } catch (Throwable $e) {
        http_response_code(500);
        echo auth_page(
            'Erreur token',
            '<h1>Échec de l’échange de code</h1>'
            . '<p class="err">' . auth_h($e->getMessage()) . '</p>'
            . '<p>Si Spotify n’envoie pas de <code>refresh_token</code>, révoquez l’app sur '
            . '<a href="https://www.spotify.com/account/apps/" rel="noopener">spotify.com/account/apps</a> '
            . 'puis rouvrez <a href="auth.php">auth.php</a>.</p>'
        );
        exit(1);
    }

    setcookie('spotify_oauth_state', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!SpotifyClient::hasRefreshToken($tokenPath)) {
        http_response_code(500);
        echo auth_page(
            'refresh_token manquant',
            '<h1>Autorisation incomplète</h1>'
            . '<p class="err">Le fichier <code>data/token.json</code> a été écrit '
            . 'mais ne contient pas de <code>refresh_token</code>.</p>'
            . '<p>Révoquez l’accès de l’app sur '
            . '<a href="https://www.spotify.com/account/apps/" rel="noopener">spotify.com/account/apps</a>, '
            . 'puis rouvrez <a href="auth.php">auth.php</a> (le consentement est forcé via '
            . '<code>show_dialog=true</code>).</p>'
        );
        exit(1);
    }

    echo auth_page(
        'Autorisation réussie',
        '<h1>Autorisation Spotify réussie</h1>'
        . '<p class="ok"><code>refresh_token</code> enregistré dans '
        . '<code>data/token.json</code>.</p>'
        . '<p>Chemin : <code>' . auth_h($tokenPath) . '</code></p>'
        . '<p>Vous pouvez lancer le cron (<code>run.php</code>) ou ouvrir '
        . '<a href="history.php">l’historique NRJ</a>.</p>'
    );
    exit(0);
}

try {
    SpotifyClient::assertTokenPathWritable($tokenPath);
} catch (Throwable $e) {
    http_response_code(500);
    echo auth_page(
        'Permissions data/',
        '<h1>Dossier data/ non inscriptible</h1>'
        . '<p class="err">' . auth_h($e->getMessage()) . '</p>'
        . '<p>Corrigez les permissions du dossier <code>data/</code> '
        . 'pour l’utilisateur PHP, puis réessayez.</p>'
    );
    exit(1);
}

$newState = bin2hex(random_bytes(16));
setcookie('spotify_oauth_state', $newState, [
    'expires'  => time() + 600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

$url = SpotifyClient::buildAuthorizeUrl($clientId, $redirectUri, $newState);
header('Location: ' . $url, true, 302);
exit(0);

function auth_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function auth_page(string $title, string $body): string
{
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . auth_h($title) . '</title>'
        . '<style>'
        . 'body{font-family:system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;line-height:1.5}'
        . '.ok{color:#0a7a32}.err{color:#b00020}'
        . 'code{background:#f3f3f3;padding:.1em .35em;border-radius:3px}'
        . '</style></head><body>' . $body . '</body></html>';
}
