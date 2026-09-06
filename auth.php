<?php

declare(strict_types=1);

/**
 * Autorisation OAuth Spotify (une fois dans le navigateur).
 * Enregistre refresh_token dans data/token.json pour run.php / cron.
 */

require __DIR__ . '/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $config = Config::load(__DIR__);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Config</title></head><body>';
    echo '<h1>Configuration manquante</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit(1);
}

$clientId = $config->string('SPOTIFY_CLIENT_ID');
$clientSecret = $config->string('SPOTIFY_CLIENT_SECRET');
$redirectUri = $config->string('SPOTIFY_REDIRECT_URI');
$tokenPath = __DIR__ . '/data/token.json';

$error = $_GET['error'] ?? null;
$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;

if (is_string($error) && $error !== '') {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Erreur</title></head><body>';
    echo '<h1>Autorisation refusée</h1><p>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit(1);
}

if (is_string($code) && $code !== '') {
    $expected = $_COOKIE['spotify_oauth_state'] ?? '';
    if (!is_string($state) || $state === '' || !hash_equals((string) $expected, $state)) {
        http_response_code(400);
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>État invalide</title></head><body>';
        echo '<h1>État OAuth invalide</h1><p>Relancez l’autorisation.</p></body></html>';
        exit(1);
    }

    try {
        SpotifyClient::exchangeCode($clientId, $clientSecret, $redirectUri, $code, $tokenPath);
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Erreur token</title></head><body>';
        echo '<h1>Échec de l’échange de code</h1><p>'
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></body></html>';
        exit(1);
    }

    setcookie('spotify_oauth_state', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>OK</title></head><body>';
    echo '<h1>Autorisation Spotify réussie</h1>';
    echo '<p>Le refresh token est enregistré dans <code>data/token.json</code>.</p>';
    echo '<p>Vous pouvez lancer <code>run.php</code> ou ouvrir <a href="history.php">l’historique NRJ</a>.</p>';
    echo '</body></html>';
    exit(0);
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
