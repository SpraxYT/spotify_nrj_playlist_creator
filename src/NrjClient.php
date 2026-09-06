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
 * Titrage NRJ sans dépendre uniquement de www.nrj.fr (souvent bloqué Cloudflare depuis un VPS).
 *
 * Titre en cours : ICY → radio-api → API nrj.fr
 * Historique : miroir HTML (VPS) → page officielle → file API
 */
final class NrjClient
{
    private const API_URL = 'https://www.nrj.fr/api/webradios/get-by-ids';
    private const HISTORY_URL = 'https://www.nrj.fr/chansons-diffusees';
    /** Miroir des titres diffusés NRJ (passe souvent hors challenge CF). */
    private const MIRROR_HISTORY_URL = 'https://myradioenligne.fr/nrj/playlist';
    private const RADIO_API_NOW =
        'https://prod.radio-api.net/stations/now-playing?stationIds=nrjfrance';
    private const TIMEOUT = 12;
    private const ICY_TIMEOUT = 8;
    private const ICY_MAX_BLOCKS = 10;
    /** Première lecture ICY (connexion unique). */
    private const ICY_MAX_ATTEMPTS = 1;
    /** Attente post-preroll pour un vrai StreamTitle (pub ~13–30 s). */
    private const ICY_POST_AD_WAIT_SEC = 22;
    private const ICY_POST_AD_INTERVAL_US = 2_500_000;
    private const ICY_POST_AD_MIN_SEC = 15;
    private const ICY_POST_AD_MAX_SEC = 25;

    /** Flux MP3 connus (hors Cloudflare). */
    private const STREAM_URLS = [
        '158' => 'https://streaming.nrjaudio.fm/oumvmk8fnozc',
        '1'   => 'https://streaming.nrjaudio.fm/ouuk8j5n3nje',
    ];

    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    private string $streamUrl;

    public function __construct(
        private readonly string $webradioId = '158',
        private readonly ?Logger $logger = null,
        ?string $streamUrl = null,
    ) {
        $this->streamUrl = $streamUrl
            ?: (self::STREAM_URLS[$this->webradioId] ?? self::STREAM_URLS['158']);
    }

    /**
     * Titre en cours.
     *
     * Priorité : StreamTitle ICY réel (hors pub / hors promo). Pendant une
     * bannière Adswizz, radio-api retarde souvent d’un titre : un repli déjà
     * en playlist (ou « last live ») est traité comme périmé, puis on attend
     * un vrai StreamTitle (~15–25 s) avant miroir / API.
     *
     * @param null|callable(NrjSong):bool $isStaleFallback
     *        true = candidat repli déjà connu (playlist / dernier live) → ignorer
     */
    public function fetchCurrentSong(?callable $isStaleFallback = null): ?NrjSong
    {
        $errors = [];
        $icyAdBanner = false;
        $adDurationMs = 0;

        try {
            $icy = $this->fetchCurrentFromIcy();
            if ($icy['song'] !== null) {
                $this->logger?->info('Titre via ICY stream : ' . $icy['song']->display());
                return $icy['song'];
            }
            if (!empty($icy['junk'])) {
                $errors[] = 'ICY : titre promo / jingle ignoré';
                $this->logger?->info('ICY : StreamTitle promo ignoré (EURO HOT / jingle).');
            } elseif ($icy['ad_banner']) {
                $icyAdBanner = true;
                $adDurationMs = (int) ($icy['ad_duration_ms'] ?? 0);
                $this->logger?->info(
                    'ICY : bannière pub (adw_ad) — repli radio-api / miroir '
                    . '(rejet si périmé, puis nouvel essai ICY).'
                );
                $errors[] = 'ICY : bannière pub (méta ad seulement)';
            } else {
                $errors[] = 'ICY : aucun titre (métadonnée vide)';
            }
        } catch (NrjClientError $e) {
            $errors[] = 'ICY : ' . $e->getMessage();
            $this->logger?->warning('ICY indisponible : ' . $e->getMessage());
        }

        $radioSong = null;
        try {
            $radioSong = $this->fetchCurrentFromRadioApi();
            if ($radioSong !== null) {
                if ($this->isFallbackStale($radioSong, $isStaleFallback)) {
                    $this->logger?->info(
                        'repli radio-api périmé (déjà en playlist), nouvel essai ICY… — '
                        . $radioSong->display()
                    );
                    $errors[] = 'radio-api : périmé (déjà en playlist) ' . $radioSong->display();
                    $radioSong = null;
                } else {
                    $src = $icyAdBanner ? 'radio-api.net (repli pub ICY)' : 'radio-api.net';
                    $this->logger?->info('Titre via ' . $src . ' : ' . $radioSong->display());
                    return $radioSong;
                }
            } else {
                $errors[] = 'radio-api : aucun titre valide';
            }
        } catch (NrjClientError $e) {
            $errors[] = 'radio-api : ' . $e->getMessage();
            $this->logger?->warning('radio-api indisponible : ' . $e->getMessage());
        }

        // Pub / métadonnée vide / repli périmé : attendre un StreamTitle post-preroll.
        if ($icyAdBanner || $radioSong === null) {
            $waited = $this->waitForIcyAfterAd($adDurationMs);
            if ($waited !== null) {
                $this->logger?->info(
                    'Titre via ICY stream (après pub) : ' . $waited->display()
                );
                return $waited;
            }
            $errors[] = 'ICY : pas de StreamTitle après attente post-pub';
        }

        try {
            $song = $this->fetchCurrentFromNrjApi();
            if ($song !== null) {
                if ($this->isFallbackStale($song, $isStaleFallback)) {
                    $this->logger?->info(
                        'repli nrj.fr API périmé (déjà en playlist), essai miroir… — '
                        . $song->display()
                    );
                    $errors[] = 'nrj.fr API : périmé ' . $song->display();
                } else {
                    $this->logger?->info('Titre via API nrj.fr : ' . $song->display());
                    return $song;
                }
            } else {
                $errors[] = 'nrj.fr API : aucun titre valide';
            }
        } catch (NrjClientError $e) {
            $errors[] = 'nrj.fr API : ' . $e->getMessage();
            $this->logger?->warning(
                'API nrj.fr indisponible (souvent Cloudflare VPS) : ' . $e->getMessage()
            );
        }

        // Miroir re-fetch : plus récent titre musical non périmé (hors promo).
        try {
            $recent = $this->fetchRecentSongs();
            foreach ($recent as $song) {
                if (self::isJunk($song->artist, $song->title)) {
                    continue;
                }
                if ($this->isFallbackStale($song, $isStaleFallback)) {
                    $this->logger?->info(
                        'repli miroir périmé (déjà en playlist), suivant… — '
                        . $song->display()
                    );
                    continue;
                }
                $src = $icyAdBanner
                    ? 'miroir (repli pub ICY)'
                    : 'miroir (fallback)';
                $this->logger?->info('Titre via ' . $src . ' : ' . $song->display());
                return $song;
            }
            $errors[] = 'historique : aucun titre musical frais (promos / périmés filtrés)';
        } catch (NrjClientError $e) {
            $errors[] = 'historique : ' . $e->getMessage();
        }

        $this->logger?->info(
            'Aucun titre NRJ valide en cours. ' . implode(' | ', $errors)
        );
        return null;
    }

    /**
     * @param null|callable(NrjSong):bool $isStaleFallback
     */
    private function isFallbackStale(NrjSong $song, ?callable $isStaleFallback): bool
    {
        if (self::isJunk($song->artist, $song->title)) {
            return true;
        }
        if ($isStaleFallback === null) {
            return false;
        }
        return (bool) $isStaleFallback($song);
    }

    /**
     * Relit le flux ICY pendant ~15–25 s (durée pub si connue) jusqu’à un
     * StreamTitle musical — prioritaire sur tout repli périmé.
     */
    private function waitForIcyAfterAd(int $adDurationMs = 0): ?NrjSong
    {
        $waitSec = self::ICY_POST_AD_WAIT_SEC;
        if ($adDurationMs > 0) {
            $fromAd = (int) ceil($adDurationMs / 1000) + 2;
            $waitSec = max(
                self::ICY_POST_AD_MIN_SEC,
                min(self::ICY_POST_AD_MAX_SEC, $fromAd)
            );
        }

        $deadline = microtime(true) + $waitSec;
        $attempt = 0;
        $this->logger?->info(
            sprintf(
                'ICY : attente StreamTitle post-pub jusqu’à %d s…',
                $waitSec
            )
        );

        while (microtime(true) < $deadline) {
            $attempt++;
            usleep(self::ICY_POST_AD_INTERVAL_US);
            try {
                $icy = $this->fetchCurrentFromIcy();
            } catch (NrjClientError $e) {
                $this->logger?->warning(
                    'ICY essai #' . $attempt . ' : ' . $e->getMessage()
                );
                continue;
            }
            if ($icy['song'] !== null) {
                return $icy['song'];
            }
            if (!empty($icy['junk'])) {
                $this->logger?->info(
                    'ICY essai #' . $attempt . ' : promo ignorée, on continue…'
                );
                continue;
            }
            if ($icy['ad_banner']) {
                $this->logger?->info(
                    'ICY essai #' . $attempt . ' : pub toujours en cours…'
                );
            }
        }

        return null;
    }

    /**
     * Historique récent — miroir d’abord (VPS), puis page officielle / API.
     *
     * @return list<NrjSong>
     */
    public function fetchRecentSongs(): array
    {
        $errors = [];

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

        try {
            $songs = $this->fetchRecentFromOfficialHtml();
            if ($songs !== []) {
                $this->logger?->info(
                    'Historique NRJ via chansons-diffusees (' . count($songs) . ' titre(s)).'
                );
                return $songs;
            }
            $errors[] = 'chansons-diffusees : vide';
        } catch (NrjClientError $e) {
            $errors[] = 'chansons-diffusees : ' . $e->getMessage();
        }

        try {
            $songs = $this->fetchPlaylistFromApi();
            if ($songs !== []) {
                $this->logger?->info(
                    'Historique partiel via API webradio (' . count($songs) . ' titre(s)).'
                );
                return $songs;
            }
            $errors[] = 'API playlist : vide';
        } catch (NrjClientError $e) {
            $errors[] = 'API playlist : ' . $e->getMessage();
        }

        throw new NrjClientError(
            'Historique distant NRJ inaccessible. Détails : ' . implode(' | ', $errors)
        );
    }

    /**
     * Promo / jingle / habillage on-air — à exclure de Spotify et des listes UI.
     * Toute occurrence EURO HOT / NRJ EURO / EUROHOT / « hits les plus diffusés »
     * est rejetée (ICY, radio-api, miroir, live, history).
     */
    public static function isJunk(string $artist, string $title): bool
    {
        if (trim($artist) === '' || trim($title) === '') {
            return true;
        }

        $combined = str_lower($artist . ' ' . $title);
        $normalized = str_replace(
            ['é', 'è', 'ê', 'ë', 'à', 'â', 'ù', 'û', 'ô', 'î', 'ï', 'ç'],
            ['e', 'e', 'e', 'e', 'a', 'a', 'u', 'u', 'o', 'i', 'i', 'c'],
            $combined
        );
        $compact = (string) preg_replace('/\s+/', '', $normalized);
        $artistU = strtoupper(trim($artist));
        $titleU = strtoupper(trim($title));

        if ($artistU === 'NRJ' && str_len(trim($title)) < 3) {
            return true;
        }

        $promoNeedles = [
            'hit music only',
            'euro hot',
            'eurohot',
            'nrj euro',
            'hits les plus diffus',
            '30 hits les plus',
            'les 30 hits',
            'hot 30',
            'jingle',
            'habillage',
            'indicatif',
            'publicité',
            'publicite',
            'jingle pub',
            'spot pub',
            'pub nrj',
            'nrj next',
            'webradio',
        ];
        foreach ($promoNeedles as $kw) {
            $kwNorm = str_replace(
                ['é', 'è', 'ê'],
                ['e', 'e', 'e'],
                $kw
            );
            if (
                str_contains($normalized, $kwNorm)
                || str_contains($compact, str_replace(' ', '', $kwNorm))
            ) {
                return true;
            }
        }

        if ($titleU === 'NRJ' && str_contains($combined, 'hit music')) {
            return true;
        }
        if ($artistU === 'NRJ' && $titleU === 'NRJ') {
            return true;
        }
        // Artiste = NRJ + titre promo long
        if ($artistU === 'NRJ' && str_len(trim($title)) > 40) {
            return true;
        }
        if (str_starts_with($titleU, 'NRJ ') && str_contains($combined, 'hit')) {
            return true;
        }

        return false;
    }

    /**
     * Lecture ICY fraîche (connexion close, pas de keep-alive).
     *
     * @return array{song:?NrjSong,ad_banner:bool,junk:bool,ad_duration_ms:int}
     */
    private function fetchCurrentFromIcy(): array
    {
        $empty = [
            'song'           => null,
            'ad_banner'      => false,
            'junk'           => false,
            'ad_duration_ms' => 0,
        ];

        try {
            // Connexion neuve (CURLOPT Connection: close).
            $meta = $this->readIcyStreamTitle($this->streamUrl);
        } catch (NrjClientError $e) {
            $this->logger?->warning('ICY : ' . $e->getMessage());
            return $empty;
        }
        if ($meta === null) {
            return $empty;
        }

        $adDurationMs = (int) ($meta['ad_duration_ms'] ?? 0);

        // Bannière Adswizz : StreamTitle vide + adw_ad.
        if (!empty($meta['is_ad'])) {
            $this->logger?->info(
                'ICY méta pub : ' . substr($meta['raw'], 0, 100)
            );
            return [
                'song'           => null,
                'ad_banner'      => true,
                'junk'           => false,
                'ad_duration_ms' => $adDurationMs,
            ];
        }

        $titleRaw = trim($meta['stream_title']);
        if ($titleRaw === '') {
            return $empty;
        }

        // Promo brute sans séparateur artiste/titre (ex. « NRJ EURO HOT 30 »).
        if (self::isJunk('NRJ', $titleRaw) || self::isJunk($titleRaw, $titleRaw)) {
            $this->logger?->info(
                'ICY StreamTitle promo : ' . substr($titleRaw, 0, 80)
            );
            return [
                'song'           => null,
                'ad_banner'      => false,
                'junk'           => true,
                'ad_duration_ms' => 0,
            ];
        }

        $parsed = $this->parseArtistTitle($titleRaw);
        if ($parsed === null) {
            $this->logger?->info('ICY StreamTitle non parsé : ' . substr($titleRaw, 0, 80));
            return $empty;
        }

        [$artist, $title] = $parsed;
        if ($this->shouldSkip($artist, $title)) {
            $this->logger?->info(
                'ICY StreamTitle junk : ' . $artist . ' - ' . $title
            );
            return [
                'song'           => null,
                'ad_banner'      => false,
                'junk'           => true,
                'ad_duration_ms' => 0,
            ];
        }

        return [
            'song' => new NrjSong(
                $this->stableSongId($artist, $title, null),
                $artist,
                $title
            ),
            'ad_banner'      => false,
            'junk'           => false,
            'ad_duration_ms' => 0,
        ];
    }

    /**
     * @return array{stream_title:string,is_ad:bool,raw:string,ad_duration_ms:int}|null
     */
    private function readIcyStreamTitle(string $url): ?array
    {
        $payload = function_exists('curl_init')
            ? $this->readIcyBufferCurl($url)
            : $this->readIcyBufferStream($url);

        return $this->parseIcyBuffer($payload['buffer'], $payload['meta_int']);
    }

    /**
     * @return array{buffer:string,meta_int:int}
     */
    private function readIcyBufferCurl(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new NrjClientError('Impossible d’ouvrir le flux ICY');
        }

        $buffer = '';
        $metaInt = 0;

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::ICY_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FRESH_CONNECT  => true,
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_HTTPHEADER     => [
                'Icy-MetaData: 1',
                'Accept: */*',
                'Connection: close',
                'Cache-Control: no-cache',
            ],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$metaInt): int {
                if (preg_match('/^icy-metaint:\s*(\d+)/i', $line, $m)) {
                    $metaInt = (int) $m[1];
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use (&$buffer, &$metaInt): int {
                $buffer .= $data;
                $need = ($metaInt > 0 ? $metaInt : 16000) * 4 + 4096;
                if (strlen($buffer) >= $need) {
                    return 0;
                }
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        // CURLE_WRITE_ERROR (23) attendu quand on coupe le flux volontairement
        if ($errno !== 0 && $errno !== 23 && $buffer === '') {
            throw new NrjClientError('Échec lecture flux ICY : ' . $err);
        }
        if ($metaInt <= 0) {
            throw new NrjClientError('Flux sans icy-metaint (métadonnées absentes)');
        }

        return ['buffer' => $buffer, 'meta_int' => $metaInt];
    }

    /**
     * Fallback sans extension cURL (fopen / sockets).
     *
     * @return array{buffer:string,meta_int:int}
     */
    private function readIcyBufferStream(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new NrjClientError('URL de flux ICY invalide');
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $remote = ($scheme === 'https' ? 'ssl://' : '') . $host . ':' . $port;

        $fp = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT
        );
        if ($fp === false) {
            throw new NrjClientError('Connexion flux ICY impossible : ' . $errstr);
        }

        stream_set_timeout($fp, self::ICY_TIMEOUT);
        $req = "GET {$path} HTTP/1.0\r\n"
            . "Host: {$host}\r\n"
            . 'User-Agent: ' . self::USER_AGENT . "\r\n"
            . "Icy-MetaData: 1\r\n"
            . "Accept: */*\r\n"
            . "Connection: close\r\n\r\n";
        fwrite($fp, $req);

        $headers = '';
        while (!feof($fp)) {
            $line = fgets($fp, 2048);
            if ($line === false) {
                break;
            }
            $headers .= $line;
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }

        $metaInt = 0;
        if (preg_match('/icy-metaint:\s*(\d+)/i', $headers, $m)) {
            $metaInt = (int) $m[1];
        }
        if ($metaInt <= 0) {
            fclose($fp);
            throw new NrjClientError('Flux sans icy-metaint (métadonnées absentes)');
        }

        $need = $metaInt * 4 + 4096;
        $buffer = '';
        while (!feof($fp) && strlen($buffer) < $need) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }
        fclose($fp);

        return ['buffer' => $buffer, 'meta_int' => $metaInt];
    }

    /**
     * @return array{stream_title:string,is_ad:bool,raw:string,ad_duration_ms:int}|null
     */
    private function parseIcyBuffer(string $buffer, int $metaInt): ?array
    {
        $offset = 0;
        $len = strlen($buffer);
        $lastAd = null;

        for ($i = 0; $i < self::ICY_MAX_BLOCKS && ($offset + $metaInt) <= $len; $i++) {
            $offset += $metaInt;
            if ($offset >= $len) {
                break;
            }
            $lengthByte = ord($buffer[$offset]);
            $offset++;
            $metaLen = $lengthByte * 16;
            if ($metaLen <= 0) {
                continue;
            }
            if ($offset + $metaLen > $len) {
                break;
            }
            $raw = rtrim(substr($buffer, $offset, $metaLen), "\0");
            $offset += $metaLen;
            if ($raw === '') {
                continue;
            }

            $streamTitle = $this->extractStreamTitle($raw);

            $isAd = (bool) preg_match(
                "/adw_ad='true'|insertionType='(?:preroll|midroll|ad)'/i",
                $raw
            );

            $adDurationMs = 0;
            if (preg_match("/durationMilliseconds='(\d+)'/i", $raw, $dm)) {
                $adDurationMs = (int) $dm[1];
            }

            $candidate = [
                'stream_title'   => trim($streamTitle),
                'is_ad'          => $isAd,
                'raw'            => $raw,
                'ad_duration_ms' => $adDurationMs,
            ];

            if ($isAd || $candidate['stream_title'] === '') {
                $lastAd = $candidate;
                continue;
            }

            return $candidate;
        }

        return $lastAd;
    }

    private function fetchCurrentFromRadioApi(): ?NrjSong
    {
        $body = $this->httpGet(
            self::RADIO_API_NOW,
            'application/json',
            'https://www.radio.net/',
            'https://www.radio.net'
        );
        $data = json_decode($body, true);
        if (!is_array($data) || $data === []) {
            return null;
        }

        $row = $data[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        $combined = trim((string) ($row['title'] ?? ''));
        if ($combined === '') {
            return null;
        }

        $parsed = $this->parseArtistTitle($combined);
        if ($parsed === null) {
            return null;
        }

        [$artist, $title] = $parsed;
        if ($this->shouldSkip($artist, $title)) {
            return null;
        }

        return new NrjSong($this->stableSongId($artist, $title, null), $artist, $title);
    }

    private function fetchCurrentFromNrjApi(): ?NrjSong
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

        return $this->songFromApiEntry($entry, true);
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
        $seen = [];
        foreach ($playlist as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $song = $this->songFromApiEntry($entry, false);
            if ($song === null || isset($seen[$song->songId])) {
                continue;
            }
            $seen[$song->songId] = true;
            $songs[] = $song;
        }
        return $songs;
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
                'Webradio ' . $this->webradioId . ' absente de la réponse'
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
                $this->logger?->info(
                    sprintf('API webradio périmée (end_timestamp=%.0f), ignorée.', (float) $endTs)
                );
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

    /**
     * Extrait StreamTitle depuis un bloc métadonnées ICY (guillemets simples/doubles ou brut).
     * Contenu jusqu’au `;` pour tolérer les apostrophes dans les noms d’artistes.
     */
    private function extractStreamTitle(string $raw): string
    {
        // StreamTitle='…'; (apostrophes internes OK)
        if (preg_match("/StreamTitle='([^;]*)'\s*;/s", $raw, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/StreamTitle="([^;]*)"\s*;/s', $raw, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        // Sans guillemets : StreamTitle=Artist - Title;
        if (preg_match('/StreamTitle=([^;]+)/i', $raw, $m)) {
            $val = trim($m[1], " \t\"'");
            return html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function parseArtistTitle(string $combined): ?array
    {
        $combined = trim($combined);
        if ($combined === '') {
            return null;
        }

        // Formats courants : "Artist - Title", "Artist – Title", "Artist — Title"
        if (preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', $combined, $m)) {
            $artist = trim($m[1]);
            $title = trim($m[2]);
            if ($artist !== '' && $title !== '') {
                return [$artist, $title];
            }
        }

        // Variante "Artist: Title"
        if (preg_match('/^(.+?)\s*:\s+(.+)$/u', $combined, $m)) {
            $artist = trim($m[1]);
            $title = trim($m[2]);
            if ($artist !== '' && $title !== '' && !str_contains($artist, 'http')) {
                return [$artist, $title];
            }
        }

        return null;
    }

    private function shouldSkip(string $artist, string $title): bool
    {
        return self::isJunk($artist, $title);
    }

    /**
     * @return list<NrjSong>
     */
    private function fetchRecentFromMirror(): array
    {
        $html = $this->httpGet(
            self::MIRROR_HISTORY_URL,
            'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            'https://myradioenligne.fr/',
            'https://myradioenligne.fr'
        );

        return $this->parseMirrorHtml($html);
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

    private function httpGet(
        string $url,
        string $accept,
        ?string $referer = null,
        ?string $origin = null
    ): string {
        $referer ??= 'https://www.nrj.fr/';
        $origin ??= (str_contains($url, 'radio-api.net') || str_contains($url, 'radio.net'))
            ? 'https://www.radio.net'
            : 'https://www.nrj.fr';

        try {
            $res = Http::request('GET', $url, null, [
                'Accept'          => $accept,
                'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                'User-Agent'      => self::USER_AGENT,
                'Referer'         => $referer,
                'Origin'          => $origin,
                'Cache-Control'   => 'no-cache',
            ], self::TIMEOUT);
        } catch (Throwable $e) {
            throw new NrjClientError('Échec HTTP : ' . $e->getMessage(), 0, $e);
        }

        $headers = $res['headers'] ?? [];
        if (Http::isCloudflareChallenge($res['status'], $res['body'], $headers)) {
            throw new NrjClientError(Http::cloudflareErrorMessage($url));
        }

        if ($res['status'] !== 200) {
            throw new NrjClientError(
                'HTTP ' . $res['status'] . ' : ' . substr($res['body'], 0, 160)
            );
        }

        return $res['body'];
    }
}
