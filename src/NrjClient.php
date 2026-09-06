<?php

declare(strict_types=1);

final class NrjSong
{
    public function __construct(
        public readonly string $songId,
        public readonly string $artist,
        public readonly string $title,
    ) {
    }

    public function display(): string
    {
        return $this->artist . ' - ' . $this->title;
    }
}

final class NrjClientError extends RuntimeException
{
}

/**
 * Récupère le titrage NRJ.
 *
 * - Titre en cours : API JSON officielle (passe Cloudflare depuis un VPS).
 * - Historique : miroir HTML sans CF, sinon page officielle, sinon file d’attente API.
 */
final class NrjClient
{
    private const API_URL = 'https://www.nrj.fr/api/webradios/get-by-ids';
    private const HISTORY_URL = 'https://www.nrj.fr/chansons-diffusees';
    /** Miroir des titres diffusés NRJ (pas derrière le challenge CF de nrj.fr). */
    private const MIRROR_HISTORY_URL = 'https://myradioenligne.fr/nrj/playlist';
    private const TIMEOUT = 15;

    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    public function __construct(
        private readonly string $webradioId = '158',
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Historique récent — priorise les sources qui ne passent pas par le challenge CF.
     *
     * @return list<NrjSong>
     */
    public function fetchRecentSongs(): array
    {
        $errors = [];

        // 1) Miroir (fonctionne depuis IP datacenter)
        try {
            $songs = $this->fetchRecentFromMirror();
            if ($songs !== []) {
                $this->logger?->info(
                    'Historique NRJ via miroir (' . count($songs) . ' titre(s)).'
                );
                return $songs;
            }
            $errors[] = 'miroir : aucun titre parsé';
        } catch (NrjClientError $e) {
            $errors[] = 'miroir : ' . $e->getMessage();
            $this->logger?->warning('Historique miroir indisponible : ' . $e->getMessage());
        }

        // 2) Page officielle (souvent bloquée CF depuis un VPS)
        try {
            $songs = $this->fetchRecentFromOfficialHtml();
            if ($songs !== []) {
                $this->logger?->info(
                    'Historique NRJ via chansons-diffusees (' . count($songs) . ' titre(s)).'
                );
                return $songs;
            }
            $errors[] = 'chansons-diffusees : aucun titre parsé';
        } catch (NrjClientError $e) {
            $errors[] = 'chansons-diffusees : ' . $e->getMessage();
            $this->logger?->warning(
                'Historique HTML NRJ indisponible : ' . $e->getMessage()
            );
        }

        // 3) File d’attente API (quelques titres à venir / en cours — JSON officiel)
        try {
            $songs = $this->fetchPlaylistFromApi();
            if ($songs !== []) {
                $this->logger?->info(
                    'Historique partiel via API webradio (' . count($songs) . ' titre(s)).'
                );
                return $songs;
            }
            $errors[] = 'API webradio : aucun titre valide';
        } catch (NrjClientError $e) {
            $errors[] = 'API webradio : ' . $e->getMessage();
        }

        throw new NrjClientError(
            'Impossible de récupérer l’historique NRJ. '
            . implode(' | ', $errors)
            . ' Astuce : le cron run.php (API JSON) capture le titre en cours ; '
            . 'l’historique complet peut aussi être lancé depuis une IP résidentielle.'
        );
    }

    /**
     * Titre en cours : API webradio d’abord (fiable depuis VPS), puis historique.
     */
    public function fetchCurrentSong(): ?NrjSong
    {
        try {
            $fromApi = $this->fetchCurrentFromApi();
            if ($fromApi !== null) {
                return $fromApi;
            }
        } catch (NrjClientError $e) {
            $this->logger?->warning(
                'API webradio indisponible (' . $e->getMessage() . ') — fallback historique.'
            );
        }

        try {
            $recent = $this->fetchRecentSongs();
            return $recent[0] ?? null;
        } catch (NrjClientError $e) {
            $this->logger?->warning(
                'Historique indisponible pour le titre en cours : ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * @return list<NrjSong>
     */
    private function fetchRecentFromMirror(): array
    {
        $html = $this->httpGet(
            self::MIRROR_HISTORY_URL,
            'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            'https://myradioenligne.fr/'
        );

        return $this->parseMirrorHtml($html);
    }

    /**
     * @return list<NrjSong>
     */
    private function fetchRecentFromOfficialHtml(): array
    {
        $url = self::HISTORY_URL . '?' . http_build_query([
            'webradio' => $this->webradioId,
            '_'        => (string) time(),
        ]);

        $html = $this->httpGet($url, 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8');
        return $this->parseHistoryHtml($html);
    }

    /**
     * @return list<NrjSong>
     */
    private function fetchPlaylistFromApi(): array
    {
        $station = $this->fetchStationPayload();
        $playlist = $station['playlist'] ?? [];
        if (!is_array($playlist) || $playlist === []) {
            return [];
        }

        $songs = [];
        $seenIds = [];
        foreach ($playlist as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $song = $this->songFromApiEntry($entry, false);
            if ($song === null || isset($seenIds[$song->songId])) {
                continue;
            }
            $seenIds[$song->songId] = true;
            $songs[] = $song;
        }

        return $songs;
    }

    private function fetchCurrentFromApi(): ?NrjSong
    {
        $station = $this->fetchStationPayload();
        $playlist = $station['playlist'] ?? [];
        if (!is_array($playlist) || $playlist === []) {
            return null;
        }

        $entry = $playlist[0];
        if (!is_array($entry)) {
            return null;
        }

        $endTs = $entry['end_timestamp'] ?? null;
        if (is_numeric($endTs) && (float) $endTs < time() - 30) {
            $this->logger?->info(
                sprintf('API webradio périmée (end_timestamp=%.0f), ignorée.', (float) $endTs)
            );
            return null;
        }

        return $this->songFromApiEntry($entry, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchStationPayload(): array
    {
        $url = self::API_URL . '?ids[]=' . rawurlencode($this->webradioId);

        $body = $this->httpGet($url, 'application/json,text/plain,*/*;q=0.8');
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new NrjClientError('Réponse NRJ API non JSON');
        }

        $station = $data[$this->webradioId] ?? null;
        if (!is_array($station)) {
            throw new NrjClientError(
                'Webradio ' . $this->webradioId . ' absente de la réponse : '
                . implode(', ', array_map('strval', array_keys($data)))
            );
        }

        return $station;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function songFromApiEntry(array $entry, bool $respectEndTimestamp): ?NrjSong
    {
        if ($respectEndTimestamp) {
            $endTs = $entry['end_timestamp'] ?? null;
            if (is_numeric($endTs) && (float) $endTs < time() - 30) {
                return null;
            }
        }

        $song = $entry['song'] ?? null;
        if (!is_array($song)) {
            return null;
        }

        $rawId = $song['id'] ?? null;
        $songId = is_scalar($rawId) && (string) $rawId !== '' && (string) $rawId !== '0'
            ? (string) $rawId
            : '';
        $artist = trim((string) ($song['artist'] ?? ''));
        $title = trim((string) ($song['title'] ?? ''));

        if ($this->shouldSkip($artist, $title)) {
            return null;
        }

        if ($songId === '') {
            $songId = $this->stableSongId($artist, $title, null);
        }

        return new NrjSong($songId, $artist, $title);
    }

    /**
     * @return list<NrjSong>
     */
    private function parseMirrorHtml(string $html): array
    {
        $songs = [];
        $seenIds = [];

        if (!preg_match_all(
            '/data-youtube="([^"]*)"[^>]*>.*?<span itemprop="byArtist">\s*([^<]+?)\s*<\/span>'
            . '\s*-\s*<span itemprop="name">\s*([^<]+?)\s*<\/span>/s',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            // Variante sans data-youtube sur le même nœud
            if (!preg_match_all(
                '/<span itemprop="byArtist">\s*([^<]+?)\s*<\/span>\s*-\s*'
                . '<span itemprop="name">\s*([^<]+?)\s*<\/span>/s',
                $html,
                $matches2,
                PREG_SET_ORDER
            )) {
                return [];
            }
            foreach ($matches2 as $m) {
                $artist = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $title = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($this->shouldSkip($artist, $title)) {
                    continue;
                }
                $songId = $this->stableSongId($artist, $title, null);
                if (isset($seenIds[$songId])) {
                    continue;
                }
                $seenIds[$songId] = true;
                $songs[] = new NrjSong($songId, $artist, $title);
            }
            return $songs;
        }

        foreach ($matches as $m) {
            $clip = trim($m[1]);
            $artist = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = html_entity_decode(trim($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($this->shouldSkip($artist, $title)) {
                continue;
            }
            $songId = $this->stableSongId($artist, $title, $clip !== '' ? $clip : null);
            if (isset($seenIds[$songId])) {
                continue;
            }
            $seenIds[$songId] = true;
            $songs[] = new NrjSong($songId, $artist, $title);
        }

        return $songs;
    }

    /**
     * @return list<NrjSong>
     */
    private function parseHistoryHtml(string $html): array
    {
        $blocks = preg_split('/<div class="listPlaylist-item">/', $html);
        if ($blocks === false || count($blocks) < 2) {
            return [];
        }

        array_shift($blocks);
        $songs = [];
        $seenIds = [];

        foreach ($blocks as $block) {
            if (
                !preg_match(
                    '/c-card-playlist__title[^>]*>\s*([^<]+?)\s*<\/strong>/s',
                    $block,
                    $artistM
                )
                || !preg_match('/<span class="description">([^<]*)<\/span>/', $block, $titleM)
            ) {
                continue;
            }

            $artist = html_entity_decode(trim($artistM[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = html_entity_decode(trim($titleM[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($this->shouldSkip($artist, $title)) {
                continue;
            }

            $clip = null;
            if (preg_match('/data-clip="([^"]*)"/', $block, $clipM)) {
                $clipVal = trim($clipM[1]);
                $clip = $clipVal !== '' ? $clipVal : null;
            }

            $songId = $this->stableSongId($artist, $title, $clip);
            if (isset($seenIds[$songId])) {
                continue;
            }
            $seenIds[$songId] = true;
            $songs[] = new NrjSong($songId, $artist, $title);
        }

        return $songs;
    }

    private function shouldSkip(string $artist, string $title): bool
    {
        if (trim($artist) === '' || trim($title) === '') {
            return true;
        }

        $combined = str_lower($artist . ' ' . $title);
        $artistU = strtoupper(trim($artist));
        $titleU = strtoupper(trim($title));

        if ($artistU === 'NRJ' && str_len(trim($title)) < 3) {
            return true;
        }

        // Habillage / claim on-air (API webradio 158)
        if (
            str_contains($combined, 'hit music only')
            || ($titleU === 'NRJ' && str_contains($combined, 'hit music'))
            || ($artistU === 'NRJ' && $titleU === 'NRJ')
        ) {
            return true;
        }

        foreach (['publicité', 'jingle pub', 'spot pub', 'pub nrj'] as $kw) {
            if (str_contains($combined, $kw)) {
                return true;
            }
        }

        return false;
    }

    private function stableSongId(string $artist, string $title, ?string $clip): string
    {
        if ($clip !== null && $clip !== '') {
            return 'clip:' . $clip;
        }

        $digest = substr(
            sha1(str_lower(trim($artist)) . '|' . str_lower(trim($title))),
            0,
            16
        );
        return 'hist:' . $digest;
    }

    private function httpGet(string $url, string $accept, ?string $referer = null): string
    {
        $referer ??= 'https://www.nrj.fr/';
        $origin = 'https://www.nrj.fr';
        if (str_contains($url, 'myradioenligne.fr')) {
            $origin = 'https://myradioenligne.fr';
        }

        try {
            $res = Http::request('GET', $url, null, [
                'Accept'                    => $accept,
                'Accept-Language'           => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'User-Agent'                => self::USER_AGENT,
                'Referer'                   => $referer,
                'Origin'                    => $origin,
                'Cache-Control'             => 'no-cache',
                'Pragma'                    => 'no-cache',
                'Upgrade-Insecure-Requests' => '1',
            ], self::TIMEOUT);
        } catch (Throwable $e) {
            throw new NrjClientError('Échec HTTP NRJ : ' . $e->getMessage(), 0, $e);
        }

        if (Http::isCloudflareChallenge($res['status'], $res['body'], $res['headers'] ?? [])) {
            throw new NrjClientError(Http::cloudflareErrorMessage($url));
        }

        if ($res['status'] !== 200) {
            throw new NrjClientError(
                'HTTP ' . $res['status'] . ' NRJ : ' . substr($res['body'], 0, 200)
            );
        }

        if (Http::isCloudflareChallenge(200, $res['body'], $res['headers'] ?? [])) {
            throw new NrjClientError(Http::cloudflareErrorMessage($url));
        }

        return $res['body'];
    }
}
