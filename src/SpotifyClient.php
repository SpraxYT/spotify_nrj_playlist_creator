<?php

declare(strict_types=1);

final class SpotifyClientError extends RuntimeException
{
}

/**
 * Client Spotify : refresh token, recherche, dédoublonnage, ajout playlist.
 */
final class SpotifyClient
{
    private const SCOPES = 'playlist-modify-public playlist-modify-private playlist-read-private';
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';
    private const API_BASE = 'https://api.spotify.com/v1';
    private const TIMEOUT = 20;

    private string $accessToken = '';
    private int $expiresAt = 0;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly string $playlistId,
        private readonly string $tokenPath,
        private readonly Cache $cache,
        private readonly ?Logger $logger = null,
    ) {
        $this->ensureAccessToken();
        $this->refreshPlaylistUris();
    }

    public static function scopes(): string
    {
        return self::SCOPES;
    }

    public static function buildAuthorizeUrl(
        string $clientId,
        string $redirectUri,
        string $state
    ): string {
        return 'https://accounts.spotify.com/authorize?' . http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'scope'         => self::SCOPES,
            'state'         => $state,
            'show_dialog'   => 'true',
        ]);
    }

    /**
     * Échange le code OAuth contre des tokens et les enregistre.
     * Spotify omet parfois refresh_token en ré-auth : on conserve l’ancien s’il existe.
     *
     * @return array<string, mixed>
     */
    public static function exchangeCode(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $code,
        string $tokenPath
    ): array {
        $payload = http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
        ]);

        $tokens = self::tokenRequest($clientId, $clientSecret, $payload);

        $newRefresh = $tokens['refresh_token'] ?? null;
        if (!is_string($newRefresh) || $newRefresh === '') {
            $existing = self::readTokenFile($tokenPath);
            $oldRefresh = $existing['refresh_token'] ?? null;
            if (is_string($oldRefresh) && $oldRefresh !== '') {
                $tokens['refresh_token'] = $oldRefresh;
            }
        }

        self::saveTokens($tokenPath, $tokens);

        $savedRefresh = $tokens['refresh_token'] ?? null;
        if (!is_string($savedRefresh) || $savedRefresh === '') {
            throw new SpotifyClientError(
                'Spotify n’a pas renvoyé de refresh_token. Révoquez l’accès de l’app sur '
                . 'https://www.spotify.com/account/apps/ puis rouvrez auth.php '
                . '(show_dialog force le consentement).'
            );
        }

        return $tokens;
    }

    /**
     * Vérifie que le dossier data/ est créable/inscriptible.
     */
    public static function assertTokenPathWritable(string $tokenPath): void
    {
        $dir = dirname($tokenPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new SpotifyClientError(
                    'Impossible de créer le dossier data/ (' . $dir . '). '
                    . 'Vérifiez les permissions du serveur (utilisateur PHP / www).'
                );
            }
        }
        if (!is_writable($dir)) {
            throw new SpotifyClientError(
                'Le dossier data/ n’est pas inscriptible (' . $dir . '). '
                . 'Corrigez les permissions (chmod/chown) pour l’utilisateur PHP.'
            );
        }
        if (is_file($tokenPath) && !is_writable($tokenPath)) {
            throw new SpotifyClientError(
                'Le fichier token.json existe mais n’est pas inscriptible (' . $tokenPath . ').'
            );
        }
    }

    /**
     * Indique si token.json contient un refresh_token non vide.
     */
    public static function hasRefreshToken(string $tokenPath): bool
    {
        $tokens = self::readTokenFile($tokenPath);
        $refresh = $tokens['refresh_token'] ?? null;
        return is_string($refresh) && $refresh !== '';
    }

    public static function normalizeQueryPart(string $value): string
    {
        $cleaned = preg_replace(
            '/\s*[\\(\\[]?\\s*(feat\\.?|ft\\.?|featuring|avec|&)\\s+.+?[\\)\\]]?\\s*$/iu',
            '',
            $value
        ) ?? $value;
        $cleaned = preg_replace('/[^\w\s]/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned;
        return trim($cleaned);
    }

    public function hasSeenNrjSong(string $nrjSongId): bool
    {
        return $this->cache->hasSeenNrjSong($nrjSongId);
    }

    /**
     * @return 'added'|'already'|'not_found'
     */
    public function addTrack(string $nrjSongId, string $artist, string $title): string
    {
        if ($this->cache->hasSeenNrjSong($nrjSongId)) {
            $this->logger?->info('Déjà traité (cache NRJ) : ' . $artist . ' - ' . $title);
            return 'already';
        }

        $track = $this->searchTrack($artist, $title);
        if ($track === null) {
            $this->logger?->warning('Introuvable sur Spotify : ' . $artist . ' - ' . $title);
            $this->cache->remember($nrjSongId, null, $artist, $title, 'not_found');
            return 'not_found';
        }

        $uri = (string) ($track['uri'] ?? '');
        $trackName = (string) ($track['name'] ?? $title);
        $trackArtists = implode(', ', array_map(
            static fn(array $a): string => (string) ($a['name'] ?? ''),
            is_array($track['artists'] ?? null) ? $track['artists'] : []
        ));

        if ($uri === '' || $this->cache->hasUri($uri)) {
            $this->logger?->info('Déjà dans la playlist : ' . $trackArtists . ' - ' . $trackName);
            $this->cache->remember($nrjSongId, $uri !== '' ? $uri : null, $artist, $title, 'already');
            return 'already';
        }

        $this->apiRequest('POST', '/playlists/' . rawurlencode($this->playlistId) . '/tracks', [
            'uris' => [$uri],
        ]);

        $this->cache->addUri($uri);
        $this->cache->remember($nrjSongId, $uri, $artist, $title, 'added');
        $this->logger?->info(
            'Ajouté avec succès : ' . $trackArtists . ' - ' . $trackName . ' (' . $uri . ')'
        );
        return 'added';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function searchTrack(string $artist, string $title): ?array
    {
        $artistQ = self::normalizeQueryPart($artist);
        $titleQ = self::normalizeQueryPart($title);

        $queries = [
            'track:"' . $titleQ . '" artist:"' . $artistQ . '"',
            $titleQ . ' ' . $artistQ,
            'track:' . $titleQ,
        ];

        foreach ($queries as $query) {
            $results = $this->apiRequest('GET', '/search?' . http_build_query([
                'q'     => $query,
                'type'  => 'track',
                'limit' => 5,
            ]));

            $items = $results['tracks']['items'] ?? [];
            if (!is_array($items) || $items === []) {
                continue;
            }

            $artistLower = str_lower($artistQ);
            $firstWord = $artistLower !== '' ? explode(' ', $artistLower)[0] : '';

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $names = '';
                foreach ($item['artists'] ?? [] as $a) {
                    if (is_array($a)) {
                        $names .= ' ' . str_lower((string) ($a['name'] ?? ''));
                    }
                }
                if ($firstWord !== '' && str_contains($names, $firstWord)) {
                    return $item;
                }
            }

            $first = $items[0];
            return is_array($first) ? $first : null;
        }

        return null;
    }

    private function refreshPlaylistUris(): void
    {
        $this->logger?->info('Chargement des titres existants de la playlist…');
        $offset = 0;
        $limit = 100;
        $count = 0;

        while (true) {
            $page = $this->apiRequest(
                'GET',
                '/playlists/' . rawurlencode($this->playlistId) . '/items?' . http_build_query([
                    'fields'            => 'items.track.uri,next',
                    'additional_types'  => 'track',
                    'limit'             => $limit,
                    'offset'            => $offset,
                ])
            );

            $items = $page['items'] ?? [];
            if (is_array($items)) {
                $uris = [];
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $uri = $item['track']['uri'] ?? null;
                    if (is_string($uri) && $uri !== '') {
                        $uris[] = $uri;
                        $count++;
                    }
                }
                $this->cache->mergeUris($uris);
            }

            if (empty($page['next'])) {
                break;
            }
            $offset += $limit;
        }

        $this->logger?->info($count . ' URI(s) déjà dans la playlist.');
    }

    private function ensureAccessToken(): void
    {
        $tokens = $this->loadTokens();
        $now = time();

        if (
            isset($tokens['access_token'], $tokens['expires_at'])
            && is_string($tokens['access_token'])
            && (int) $tokens['expires_at'] > $now + 60
        ) {
            $this->accessToken = $tokens['access_token'];
            $this->expiresAt = (int) $tokens['expires_at'];
            return;
        }

        $refresh = $tokens['refresh_token'] ?? null;
        if (!is_string($refresh) || $refresh === '') {
            throw new SpotifyClientError(
                'Aucun refresh_token. Ouvrez auth.php dans le navigateur pour autoriser l’app.'
            );
        }

        $payload = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
        ]);

        $fresh = self::tokenRequest($this->clientId, $this->clientSecret, $payload);
        if (!isset($fresh['refresh_token'])) {
            $fresh['refresh_token'] = $refresh;
        }
        self::saveTokens($this->tokenPath, $fresh);

        $this->accessToken = (string) $fresh['access_token'];
        $this->expiresAt = time() + (int) ($fresh['expires_in'] ?? 3600);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadTokens(): array
    {
        return self::readTokenFile($this->tokenPath);
    }

    /**
     * @return array<string, mixed>
     */
    public static function readTokenFile(string $tokenPath): array
    {
        if (!is_file($tokenPath)) {
            return [];
        }
        $raw = file_get_contents($tokenPath);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $tokens
     */
    public static function saveTokens(string $tokenPath, array $tokens): void
    {
        self::assertTokenPathWritable($tokenPath);

        $refresh = $tokens['refresh_token'] ?? null;
        if (!is_string($refresh) || $refresh === '') {
            $existing = self::readTokenFile($tokenPath);
            $old = $existing['refresh_token'] ?? null;
            if (is_string($old) && $old !== '') {
                $tokens['refresh_token'] = $old;
            }
        }

        $expiresIn = (int) ($tokens['expires_in'] ?? 3600);
        $payload = [
            'access_token'  => (string) ($tokens['access_token'] ?? ''),
            'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
            'token_type'    => (string) ($tokens['token_type'] ?? 'Bearer'),
            'scope'         => (string) ($tokens['scope'] ?? self::SCOPES),
            'expires_in'    => $expiresIn,
            'expires_at'    => time() + $expiresIn,
            'obtained_at'   => gmdate('c'),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new SpotifyClientError('Impossible d’encoder token.json');
        }
        if (@file_put_contents($tokenPath, $json . "\n", LOCK_EX) === false) {
            throw new SpotifyClientError(
                'Impossible d’écrire token.json (' . $tokenPath . '). '
                . 'Vérifiez les permissions du dossier data/.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function tokenRequest(
        string $clientId,
        string $clientSecret,
        string $payload
    ): array {
        try {
            $res = Http::request('POST', self::TOKEN_URL, $payload, [
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
            ], self::TIMEOUT);
        } catch (Throwable $e) {
            throw new SpotifyClientError('Échec token Spotify : ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data) || $res['status'] >= 400 || empty($data['access_token'])) {
            $msg = is_array($data)
                ? (string) ($data['error_description'] ?? $data['error'] ?? $res['body'])
                : $res['body'];
            throw new SpotifyClientError('Token Spotify HTTP ' . $res['status'] . ' : ' . $msg);
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array<string, mixed>
     */
    private function apiRequest(string $method, string $path, ?array $jsonBody = null): array
    {
        if ($this->accessToken === '' || time() >= $this->expiresAt - 30) {
            $this->ensureAccessToken();
        }

        $url = str_starts_with($path, 'http') ? $path : self::API_BASE . $path;
        $headers = [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Accept'        => 'application/json',
        ];
        $body = null;
        if ($jsonBody !== null) {
            $encoded = json_encode($jsonBody);
            if ($encoded === false) {
                throw new SpotifyClientError('JSON invalide pour requête Spotify');
            }
            $headers['Content-Type'] = 'application/json';
            $body = $encoded;
        }

        try {
            $res = Http::request($method, $url, $body, $headers, self::TIMEOUT);
        } catch (Throwable $e) {
            throw new SpotifyClientError('Échec API Spotify : ' . $e->getMessage(), 0, $e);
        }

        if ($res['status'] === 204 || $res['body'] === '') {
            return [];
        }

        $data = json_decode($res['body'], true);
        if ($res['status'] >= 400) {
            $msg = is_array($data)
                ? json_encode($data['error'] ?? $data, JSON_UNESCAPED_UNICODE)
                : $res['body'];
            throw new SpotifyClientError('API Spotify HTTP ' . $res['status'] . ' : ' . $msg);
        }

        return is_array($data) ? $data : [];
    }
}
