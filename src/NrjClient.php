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
 * Récupère le titrage NRJ (historique HTML + fallback API webradio).
 */
final class NrjClient
{
    private const API_URL = 'https://www.nrj.fr/api/webradios/get-by-ids';
    private const HISTORY_URL = 'https://www.nrj.fr/chansons-diffusees';
    private const TIMEOUT = 15;

    private const USER_AGENT =
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(
        private readonly string $webradioId = '158',
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Historique récent (page chansons-diffusees) — source la plus fiable.
     *
     * @return list<NrjSong>
     */
    public function fetchRecentSongs(): array
    {
        $url = self::HISTORY_URL . '?' . http_build_query([
            'webradio' => $this->webradioId,
            '_'        => (string) time(),
        ]);

        $html = $this->httpGet($url, 'text/html,application/xhtml+xml');
        $songs = $this->parseHistoryHtml($html);

        if ($songs === []) {
            throw new NrjClientError(
                'Aucun titre parsé sur chansons-diffusees (structure HTML peut-être changée).'
            );
        }

        return $songs;
    }

    /**
     * Titre en cours : premier de l’historique HTML, sinon API webradio.
     */
    public function fetchCurrentSong(): ?NrjSong
    {
        try {
            $recent = $this->fetchRecentSongs();
            return $recent[0];
        } catch (NrjClientError $e) {
            $this->logger?->warning(
                'Historique HTML indisponible (' . $e->getMessage() . ') — fallback API webradio.'
            );
        }

        return $this->fetchCurrentFromApi();
    }

    private function fetchCurrentFromApi(): ?NrjSong
    {
        $url = self::API_URL . '?ids[]=' . rawurlencode($this->webradioId);

        $body = $this->httpGet($url, 'application/json');
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

        $song = $entry['song'] ?? null;
        if (!is_array($song)) {
            return null;
        }

        $songId = isset($song['id']) ? (string) $song['id'] : '';
        $artist = trim((string) ($song['artist'] ?? ''));
        $title = trim((string) ($song['title'] ?? ''));

        if ($songId === '' || $this->shouldSkip($artist, $title)) {
            return null;
        }

        return new NrjSong($songId, $artist, $title);
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

            $artist = trim($artistM[1]);
            $title = trim($titleM[1]);
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
        if (strtoupper(trim($artist)) === 'NRJ' && str_len(trim($title)) < 3) {
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

    private function httpGet(string $url, string $accept): string
    {
        try {
            $res = Http::request('GET', $url, null, [
                'Accept'        => $accept,
                'User-Agent'    => self::USER_AGENT,
                'Referer'       => 'https://www.nrj.fr/',
                'Origin'        => 'https://www.nrj.fr',
                'Cache-Control' => 'no-cache',
                'Pragma'        => 'no-cache',
            ], self::TIMEOUT);
        } catch (Throwable $e) {
            throw new NrjClientError('Échec HTTP NRJ : ' . $e->getMessage(), 0, $e);
        }

        if ($res['status'] !== 200) {
            throw new NrjClientError(
                'HTTP ' . $res['status'] . ' NRJ : ' . substr($res['body'], 0, 200)
            );
        }

        return $res['body'];
    }
}
