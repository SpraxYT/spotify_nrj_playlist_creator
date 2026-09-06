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
    private readonly string $playlistId;
    private readonly string $rootDir;
    private readonly bool $strictOwnership;

    /** @var array{id:string,display_name:string,email:?string}|null */
    private ?array $me = null;

    /** @var array{id:string,name:string,owner_id:string,owner_name:string,collaborative:bool,public:?bool}|null */
    private ?array $playlistMeta = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        string $playlistId,
        private readonly string $tokenPath,
        private readonly Cache $cache,
        private readonly ?Logger $logger = null,
        bool $strictOwnership = true,
        ?string $rootDir = null,
    ) {
        // tokenPath = …/data/token.json → root = parent of data/
        $this->rootDir = $rootDir ?? dirname(dirname($tokenPath));
        $this->strictOwnership = $strictOwnership;

        $resolved = self::resolvePlaylistId($playlistId, $this->rootDir);
        $this->playlistId = self::normalizePlaylistId($resolved);
        if ($this->playlistId === '') {
            throw new SpotifyClientError(
                'SPOTIFY_PLAYLIST_ID vide. Indiquez l’ID de la playlist '
                . '(ex. dans l’URL Spotify : …/playlist/4DCkQ853je6ajt28tF6bef).'
            );
        }

        $this->ensureAccessToken();
        $this->assertAuthorizedUser();
        $this->loadPlaylistMeta();
        if ($this->strictOwnership) {
            $this->refreshPlaylistUris();
        }
    }

    /**
     * Priorité : data/playlist_id.json (créée via l’UI) puis config.
     */
    public static function resolvePlaylistId(string $configId, string $rootDir): string
    {
        $path = rtrim($rootDir, '/') . '/data/playlist_id.json';
        if (is_file($path)) {
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $id = trim((string) ($decoded['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }
        return $configId;
    }

    public static function savePlaylistIdOverride(string $rootDir, string $playlistId, string $name = ''): void
    {
        $id = self::normalizePlaylistId($playlistId);
        if ($id === '') {
            throw new SpotifyClientError('ID playlist vide — impossible d’enregistrer l’override.');
        }
        $dir = rtrim($rootDir, '/') . '/data';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new SpotifyClientError('Impossible de créer data/ pour playlist_id.json');
        }
        $payload = [
            'id'         => $id,
            'name'       => $name,
            'updated_at' => gmdate('c'),
            'source'     => 'api_create',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($dir . '/playlist_id.json', $json . "\n", LOCK_EX) === false) {
            throw new SpotifyClientError('Impossible d’écrire data/playlist_id.json');
        }
    }

    public function playlistId(): string
    {
        return $this->playlistId;
    }

    /**
     * @return array{id:string,display_name:string,email:?string}|null
     */
    public function currentUser(): ?array
    {
        return $this->me;
    }

    /**
     * @return array{id:string,name:string,owner_id:string,owner_name:string,collaborative:bool,public:?bool}|null
     */
    public function playlistInfo(): ?array
    {
        return $this->playlistMeta;
    }

    public function isOwnerMatch(): ?bool
    {
        $meId = $this->me['id'] ?? '';
        $ownerId = $this->playlistMeta['owner_id'] ?? '';
        if ($meId === '' || $ownerId === '') {
            return null;
        }
        return $meId === $ownerId;
    }

    /**
     * POST /v1/me/playlists — crée une playlist sous le compte auth.php.
     *
     * @return array{id:string,name:string,uri:string}
     */
    public function createPlaylist(string $name, string $description = '', bool $public = true): array
    {
        $data = $this->apiRequest(
            'POST',
            '/me/playlists',
            [
                'name'        => $name,
                'description' => $description,
                'public'      => $public,
            ],
            'playlist_create'
        );

        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            throw new SpotifyClientError('Création playlist : réponse sans id.');
        }

        return [
            'id'   => $id,
            'name' => (string) ($data['name'] ?? $name),
            'uri'  => (string) ($data['uri'] ?? ('spotify:playlist:' . $id)),
        ];
    }

    public static function scopes(): string
    {
        return self::SCOPES;
    }

    /**
     * Extrait un ID playlist depuis une URL, un URI Spotify, ou un ID brut.
     */
    public static function normalizePlaylistId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#spotify:playlist:([a-zA-Z0-9]+)#', $raw, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#/playlist/([a-zA-Z0-9]+)#', $raw, $m) === 1) {
            return $m[1];
        }
        // Query string éventuelle (?si=…)
        if (preg_match('#^([a-zA-Z0-9]+)#', $raw, $m) === 1) {
            return $m[1];
        }
        return $raw;
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

        // Fév. 2026 Dev Mode : POST /playlists/{id}/tracks → 403 Forbidden.
        // Utiliser /items (comme spotipy 2.26 playlist_add_items).
        $this->apiRequest(
            'POST',
            '/playlists/' . rawurlencode($this->playlistId) . '/items',
            ['uris' => [$uri]],
            'playlist_add'
        );

        $this->cache->addUri($uri);
        $this->cache->remember($nrjSongId, $uri, $artist, $title, 'added');
        $this->logger?->info(
            'Ajouté avec succès : ' . $trackArtists . ' - ' . $trackName . ' (' . $uri . ')'
        );
        return 'added';
    }

    /**
     * Ajoute un URI Spotify connu (test debug / isolation search vs add).
     *
     * @return array{ok:bool,status:int,body:string,url:string}
     */
    public function addTrackUriRaw(string $uri): array
    {
        $uri = trim($uri);
        if ($uri === '' || !str_starts_with($uri, 'spotify:')) {
            throw new SpotifyClientError('URI Spotify invalide : ' . $uri);
        }

        return $this->apiRequestRaw(
            'POST',
            '/playlists/' . rawurlencode($this->playlistId) . '/items',
            ['uris' => [$uri]],
            'playlist_add'
        );
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
            $results = $this->apiRequest(
                'GET',
                '/search?' . http_build_query([
                    'q'     => $query,
                    'type'  => 'track',
                    'limit' => 5,
                ]),
                null,
                'search'
            );

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

    /**
     * GET /v1/me — échoue clairement si l’utilisateur n’est pas dans User Management.
     */
    private function assertAuthorizedUser(): void
    {
        $res = $this->rawApiGet('/me');
        if ($res['status'] === 401 || $res['status'] === 403) {
            throw new SpotifyClientError(
                'API Spotify HTTP ' . $res['status'] . ' sur GET /v1/me. '
                . self::devModeUserManagementHint()
            );
        }
        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['data'])) {
            $detail = is_array($res['data'])
                ? (string) json_encode($res['data'], JSON_UNESCAPED_UNICODE)
                : $res['body'];
            throw new SpotifyClientError(
                'API Spotify HTTP ' . $res['status'] . ' sur GET /v1/me : ' . $detail
            );
        }

        $email = $res['data']['email'] ?? null;
        $this->me = [
            'id'           => (string) ($res['data']['id'] ?? ''),
            'display_name' => (string) ($res['data']['display_name'] ?? $res['data']['id'] ?? ''),
            'email'        => is_string($email) && $email !== '' ? $email : null,
        ];
        $this->logger?->info(
            'Spotify connecté : ' . $this->me['display_name']
            . ' (id=' . $this->me['id'] . ')'
        );
    }

    /**
     * GET /v1/playlists/{id} — compare le propriétaire au compte authentifié.
     */
    private function loadPlaylistMeta(): void
    {
        $res = $this->rawApiGet(
            '/playlists/' . rawurlencode($this->playlistId)
            . '?fields=id,name,collaborative,public,owner(id,display_name)'
        );

        if ($res['status'] === 401 || $res['status'] === 403) {
            // /me a déjà réussi : plutôt un problème d’accès playlist
            throw new SpotifyClientError(
                $this->playlistAccessDeniedMessage(
                    'lecture des métadonnées de la playlist',
                    (string) ($res['data']['error']['message'] ?? 'Forbidden')
                )
            );
        }
        if ($res['status'] === 404) {
            throw new SpotifyClientError(
                'Playlist Spotify introuvable (HTTP 404) pour SPOTIFY_PLAYLIST_ID='
                . $this->playlistId . '. Vérifiez l’ID dans l’URL '
                . '(…/playlist/4DCkQ853je6ajt28tF6bef) et que le compte auth.php y a accès.'
            );
        }
        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['data'])) {
            $detail = is_array($res['data'])
                ? (string) json_encode($res['data'], JSON_UNESCAPED_UNICODE)
                : $res['body'];
            throw new SpotifyClientError(
                'API Spotify HTTP ' . $res['status'] . ' sur GET /v1/playlists/{id} : ' . $detail
            );
        }

        $owner = is_array($res['data']['owner'] ?? null) ? $res['data']['owner'] : [];
        $public = $res['data']['public'] ?? null;
        $this->playlistMeta = [
            'id'            => (string) ($res['data']['id'] ?? $this->playlistId),
            'name'          => (string) ($res['data']['name'] ?? ''),
            'owner_id'      => (string) ($owner['id'] ?? ''),
            'owner_name'    => (string) ($owner['display_name'] ?? $owner['id'] ?? ''),
            'collaborative' => (bool) ($res['data']['collaborative'] ?? false),
            'public'        => is_bool($public) ? $public : null,
        ];

        $meId = $this->me['id'] ?? '';
        $ownerId = $this->playlistMeta['owner_id'];
        $this->logger?->info(
            'Playlist « ' . $this->playlistMeta['name'] . ' » (id=' . $this->playlistMeta['id']
            . ') — propriétaire : ' . $this->playlistMeta['owner_name']
            . ' (id=' . $ownerId . ')'
        );

        if ($meId !== '' && $ownerId !== '' && $meId !== $ownerId) {
            $msg = 'La playlist ne t’appartient pas. '
                . 'Compte authentifié (me.id) : ' . ($this->me['display_name'] ?? $meId)
                . ' (id=' . $meId . '). '
                . 'Propriétaire playlist (owner.id) : '
                . $this->playlistMeta['owner_name'] . ' (id=' . $ownerId . '). '
                . 'Playlist id=' . $this->playlistMeta['id'] . '. '
                . 'Crée une playlist avec le compte utilisé pour auth.php '
                . '(bouton dans history.php / debug.php), ou mets à jour SPOTIFY_PLAYLIST_ID.';
            if ($this->strictOwnership) {
                throw new SpotifyClientError($msg);
            }
            $this->logger?->warning($msg);
        }
    }

    private function refreshPlaylistUris(): void
    {
        $this->logger?->info('Chargement des titres existants de la playlist…');
        $offset = 0;
        $limit = 100;
        $count = 0;

        try {
            while (true) {
                $page = $this->apiRequest(
                    'GET',
                    '/playlists/' . rawurlencode($this->playlistId) . '/items?' . http_build_query([
                        'fields'           => 'items(track(uri),item(uri)),next',
                        'additional_types' => 'track',
                        'limit'            => $limit,
                        'offset'           => $offset,
                    ]),
                    null,
                    'playlist_items'
                );

                $items = $page['items'] ?? [];
                if (is_array($items)) {
                    $uris = [];
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        // Avant fév. 2026 : track.uri — après : item.uri
                        $uri = $item['track']['uri'] ?? $item['item']['uri'] ?? null;
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
        } catch (SpotifyClientError $e) {
            // Lecture items parfois plus stricte que l’ajout — on continue avec un cache vide.
            if (str_contains($e->getMessage(), 'HTTP 403')) {
                $this->logger?->warning(
                    'Lecture playlist/items en 403 — poursuite sans dédoublonnage distant. '
                    . $e->getMessage()
                );
                return;
            }
            throw $e;
        }

        $this->logger?->info($count . ' URI(s) déjà dans la playlist.');
    }

    /**
     * @return array{status:int, body:string, data:mixed}
     */
    private function rawApiGet(string $path): array
    {
        if ($this->accessToken === '' || time() >= $this->expiresAt - 30) {
            $this->ensureAccessToken();
        }

        $url = self::API_BASE . $path;
        try {
            $res = Http::request('GET', $url, null, [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept'        => 'application/json',
            ], self::TIMEOUT);
        } catch (Throwable $e) {
            throw new SpotifyClientError('Échec API Spotify : ' . $e->getMessage(), 0, $e);
        }

        return [
            'status' => $res['status'],
            'body'   => $res['body'],
            'data'   => json_decode($res['body'], true),
        ];
    }

    public static function devModeUserManagementHint(): string
    {
        return 'Ajoute ton compte Spotify dans le Dashboard (User Management) en mode Development : '
            . 'https://developer.spotify.com/dashboard → ton app → Settings / User Management → Add user '
            . '(e-mail du compte utilisé pour auth.php), puis ré-autorise via auth.php et réessaie.';
    }

    private function playlistAccessDeniedMessage(string $ctxLabel, string $spotifyMsg): string
    {
        $meLabel = ($this->me['display_name'] ?? '') !== ''
            ? $this->me['display_name'] . ' (id=' . ($this->me['id'] ?? '?') . ')'
            : 'inconnu';
        $ownerLabel = $this->playlistMeta !== null
            ? $this->playlistMeta['owner_name'] . ' (id=' . $this->playlistMeta['owner_id'] . ')'
            : 'inconnu (métadonnées non lues)';

        $meId = $this->me['id'] ?? '';
        $ownerId = $this->playlistMeta['owner_id'] ?? '';
        $meId = $this->me['id'] ?? '';
        $ownerId = $this->playlistMeta['owner_id'] ?? '';
        $meIdLabel = $meId !== '' ? $meId : '?';
        $ownerIdLabel = $ownerId !== '' ? $ownerId : '?';

        // Propriétaire OK → ne PAS accuser un mismatch : endpoint / scopes / token
        if ($meId !== '' && $ownerId !== '' && $meId === $ownerId) {
            return 'API Spotify HTTP 403 (Forbidden) lors de : ' . $ctxLabel . '. '
                . 'me.id=owner.id=' . $meIdLabel . ' (' . $meLabel . ') — '
                . 'playlist id=' . $this->playlistId . '. '
                . 'Ce n’est pas un problème de propriétaire. Causes fréquentes : '
                . '(1) ancien endpoint POST …/tracks (retiré fév. 2026 — il faut …/items) ; '
                . '(2) scopes manquants dans le token — rouvrir auth.php (show_dialog) ; '
                . '(3) Client ID config ≠ app « NRJ TUBE » du dashboard. '
                . 'Scopes requis : ' . self::SCOPES . '. '
                . ($spotifyMsg !== '' ? 'Détail Spotify : ' . $spotifyMsg : '');
        }

        if ($meId !== '' && $ownerId !== '' && $meId !== $ownerId) {
            return 'API Spotify HTTP 403 (Forbidden) lors de : ' . $ctxLabel . '. '
                . 'La playlist ne t’appartient pas. '
                . 'me.id=' . $meIdLabel . ' (' . $meLabel . ') ≠ owner.id=' . $ownerIdLabel
                . ' (' . $ownerLabel . ') — playlist id=' . $this->playlistId . '. '
                . 'Crée une playlist avec le compte auth.php (debug.php / history.php) '
                . 'ou change SPOTIFY_PLAYLIST_ID. '
                . ($spotifyMsg !== '' ? 'Détail Spotify : ' . $spotifyMsg : '');
        }

        return 'API Spotify HTTP 403 (Forbidden) lors de : ' . $ctxLabel . '. '
            . 'Compte me.id=' . $meIdLabel . ' (' . $meLabel . ') — '
            . 'owner.id=' . $ownerIdLabel . ' (' . $ownerLabel . ') — '
            . 'playlist id=' . $this->playlistId . '. '
            . 'Vérifiez scopes (auth.php), endpoint /items, et Client ID. '
            . self::devModeUserManagementHint()
            . ($spotifyMsg !== '' ? ' Détail Spotify : ' . $spotifyMsg : '');
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
        $scope = (string) ($tokens['scope'] ?? '');
        if ($scope === '') {
            $existing = self::readTokenFile($tokenPath);
            $oldScope = $existing['scope'] ?? null;
            $scope = is_string($oldScope) && $oldScope !== '' ? $oldScope : self::SCOPES;
        }
        $payload = [
            'access_token'  => (string) ($tokens['access_token'] ?? ''),
            'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
            'token_type'    => (string) ($tokens['token_type'] ?? 'Bearer'),
            'scope'         => $scope,
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
    private function apiRequest(
        string $method,
        string $path,
        ?array $jsonBody = null,
        string $context = ''
    ): array {
        $raw = $this->apiRequestRaw($method, $path, $jsonBody, $context);
        if ($raw['status'] === 204 || ($raw['body'] === '' && $raw['status'] < 400)) {
            return [];
        }

        $data = json_decode($raw['body'], true);
        if ($raw['status'] >= 400) {
            throw new SpotifyClientError(
                $this->formatApiError($raw['status'], $data, $raw['body'], $context)
                . ' [HTTP ' . $raw['status'] . ' ' . strtoupper($method) . ' ' . $raw['url'] . ']'
            );
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array{ok:bool,status:int,body:string,url:string,request_body:?string}
     */
    public function apiRequestRaw(
        string $method,
        string $path,
        ?array $jsonBody = null,
        string $context = ''
    ): array {
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
            $encoded = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new SpotifyClientError('JSON invalide pour requête Spotify');
            }
            $headers['Content-Type'] = 'application/json';
            $body = $encoded;
        }

        $this->logger?->info(
            'Spotify ' . strtoupper($method) . ' ' . $url
            . ($body !== null ? ' body=' . $body : '')
            . ($context !== '' ? ' [' . $context . ']' : '')
        );

        try {
            $res = Http::request($method, $url, $body, $headers, self::TIMEOUT);
        } catch (Throwable $e) {
            throw new SpotifyClientError('Échec API Spotify : ' . $e->getMessage(), 0, $e);
        }

        $ok = $res['status'] >= 200 && $res['status'] < 300;
        if (!$ok) {
            $this->logger?->warning(
                'Spotify HTTP ' . $res['status'] . ' ' . strtoupper($method) . ' ' . $url
                . ' → ' . substr($res['body'], 0, 500)
            );
        }

        return [
            'ok'           => $ok,
            'status'       => $res['status'],
            'body'         => $res['body'],
            'url'          => $url,
            'request_body' => $body,
        ];
    }

    /**
     * @param mixed $data
     */
    private function formatApiError(int $status, mixed $data, string $rawBody, string $context): string
    {
        $spotifyMsg = '';
        if (is_array($data)) {
            $err = $data['error'] ?? null;
            if (is_array($err)) {
                $spotifyMsg = trim((string) ($err['message'] ?? ''));
                $status = (int) ($err['status'] ?? $status);
            } elseif (is_string($err) && $err !== '') {
                $spotifyMsg = $err;
            }
        }

        $ctxLabel = match ($context) {
            'playlist_items'  => 'chargement des titres de la playlist',
            'playlist_add'    => 'ajout à la playlist',
            'playlist_create' => 'création de playlist',
            'search'          => 'recherche de titre',
            default           => $context !== '' ? $context : 'requête API',
        };

        if ($status === 403) {
            return $this->explain403($context, $ctxLabel, $spotifyMsg);
        }

        if ($status === 401) {
            return 'API Spotify HTTP 401 (non autorisé) lors de : ' . $ctxLabel . '. '
                . 'Le token est invalide ou expiré — rouvrez auth.php pour ré-autoriser. '
                . ($spotifyMsg !== '' ? 'Détail : ' . $spotifyMsg : '');
        }

        if ($status === 404) {
            return 'API Spotify HTTP 404 lors de : ' . $ctxLabel . '. '
                . 'Vérifiez SPOTIFY_PLAYLIST_ID (' . $this->playlistId . ') : playlist introuvable '
                . 'ou ID incorrect. '
                . ($spotifyMsg !== '' ? 'Détail : ' . $spotifyMsg : '');
        }

        $fallback = is_array($data)
            ? (string) json_encode($data['error'] ?? $data, JSON_UNESCAPED_UNICODE)
            : $rawBody;
        $detail = $spotifyMsg !== '' ? $spotifyMsg : $fallback;

        return 'API Spotify HTTP ' . $status . ' (' . $ctxLabel . ') : ' . $detail;
    }

    private function explain403(string $context, string $ctxLabel, string $spotifyMsg): string
    {
        $msgLower = strtolower($spotifyMsg);
        if (
            str_contains($msgLower, 'scope')
            || str_contains($msgLower, 'insufficient')
        ) {
            return 'API Spotify HTTP 403 (scopes insuffisants) lors de : ' . $ctxLabel . '. '
                . 'Ré-autorisez via auth.php (scopes requis : ' . self::SCOPES . '). '
                . ($spotifyMsg !== '' ? 'Détail Spotify : ' . $spotifyMsg : '');
        }

        // /me déjà OK en constructeur → plutôt accès playlist / ajout
        if ($context === 'playlist_items' || $context === 'playlist_add') {
            return $this->playlistAccessDeniedMessage($ctxLabel, $spotifyMsg);
        }

        if ($context === 'search') {
            return 'API Spotify HTTP 403 lors de : recherche de titre. '
                . self::devModeUserManagementHint()
                . ($spotifyMsg !== '' ? ' Détail Spotify : ' . $spotifyMsg : '');
        }

        $meOk = $this->probeCurrentUser();
        if ($meOk === false) {
            return 'API Spotify HTTP 403 (Forbidden) lors de : ' . $ctxLabel . '. '
                . self::devModeUserManagementHint()
                . ($spotifyMsg !== '' ? ' Détail Spotify : ' . $spotifyMsg : '');
        }

        return 'API Spotify HTTP 403 (Forbidden) lors de : ' . $ctxLabel . '. '
            . self::devModeUserManagementHint()
            . ($spotifyMsg !== '' ? ' Détail Spotify : ' . $spotifyMsg : '');
    }

    /**
     * true = /me OK, false = /me 403/401, null = indéterminé (réseau / autre).
     */
    private function probeCurrentUser(): ?bool
    {
        if ($this->accessToken === '') {
            return null;
        }

        try {
            $res = Http::request(
                'GET',
                self::API_BASE . '/me',
                null,
                [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Accept'        => 'application/json',
                ],
                self::TIMEOUT
            );
        } catch (Throwable) {
            return null;
        }

        if ($res['status'] >= 200 && $res['status'] < 300) {
            return true;
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            return false;
        }

        return null;
    }
}
