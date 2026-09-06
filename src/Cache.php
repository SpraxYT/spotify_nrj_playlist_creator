<?php

declare(strict_types=1);

/**
 * Cache local des titres NRJ déjà traités (+ URIs Spotify connus).
 *
 * Un titre n’est « réglé » (skip) que s’il a été ajouté (status=added)
 * ou confirmé déjà en playlist (status=already + URI). Les not_found
 * restent réessayables (ex. live radio-api avec un libellé plus propre).
 */
final class Cache
{
    private string $path;

    /** @var list<array<string, mixed>> */
    private array $entries = [];

    /** @var array<string, string> nrjSongId => last status */
    private array $statusByNrjId = [];

    /** @var array<string, true> */
    private array $uris = [];

    /** @var array<string, string> fingerprint artist|title => status */
    private array $statusByFingerprint = [];

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->load();
    }

    public function hasSeenNrjSong(string $nrjSongId): bool
    {
        return isset($this->statusByNrjId[$nrjSongId]);
    }

    /**
     * True seulement si on a déjà ajouté le titre ou confirmé l’URI en playlist.
     */
    public function isHandledSuccessfully(
        string $nrjSongId,
        string $artist = '',
        string $title = ''
    ): bool {
        $status = $this->statusByNrjId[$nrjSongId] ?? null;
        if ($status === 'added' || $status === 'already') {
            return true;
        }

        if ($artist !== '' && $title !== '') {
            $fp = $this->fingerprint($artist, $title);
            $fpStatus = $this->statusByFingerprint[$fp] ?? null;
            if ($fpStatus === 'added' || $fpStatus === 'already') {
                return true;
            }
        }

        return false;
    }

    public function hasUri(string $uri): bool
    {
        return isset($this->uris[$uri]);
    }

    public function addUri(string $uri): void
    {
        $this->uris[$uri] = true;
    }

    /**
     * @param list<string> $uris
     */
    public function mergeUris(array $uris): void
    {
        foreach ($uris as $uri) {
            if ($uri !== '') {
                $this->uris[$uri] = true;
            }
        }
    }

    public function remember(
        string $nrjSongId,
        ?string $uri,
        string $artist,
        string $title,
        string $status
    ): void {
        $this->statusByNrjId[$nrjSongId] = $status;
        if ($uri !== null && $uri !== '') {
            $this->uris[$uri] = true;
        }
        if ($artist !== '' && $title !== '') {
            $this->statusByFingerprint[$this->fingerprint($artist, $title)] = $status;
        }

        $this->entries[] = [
            'nrj_song_id' => $nrjSongId,
            'uri'         => $uri,
            'artist'      => $artist,
            'title'       => $title,
            'status'      => $status,
            'added_at'    => gmdate('c'),
        ];

        $this->save();
    }

    private function fingerprint(string $artist, string $title): string
    {
        return str_lower(trim($artist)) . '|' . str_lower(trim($title));
    }

    private function load(): void
    {
        if (!is_file($this->path)) {
            return;
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        $list = array_is_list($decoded) ? $decoded : ($decoded['tracks'] ?? []);
        if (!is_array($list)) {
            return;
        }

        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $this->entries[] = $entry;
            $nrjId = isset($entry['nrj_song_id']) ? (string) $entry['nrj_song_id'] : '';
            $uri = isset($entry['uri']) ? (string) $entry['uri'] : '';
            $status = isset($entry['status']) ? (string) $entry['status'] : 'already';
            $artist = isset($entry['artist']) ? (string) $entry['artist'] : '';
            $title = isset($entry['title']) ? (string) $entry['title'] : '';

            if ($nrjId !== '') {
                // Dernier statut gagne (liste chronologique).
                $this->statusByNrjId[$nrjId] = $status;
            }
            if ($uri !== '') {
                $this->uris[$uri] = true;
            }
            if ($artist !== '' && $title !== '') {
                $this->statusByFingerprint[$this->fingerprint($artist, $title)] = $status;
            }
        }
    }

    private function save(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = [
            'updated_at' => gmdate('c'),
            'tracks'     => $this->entries,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Impossible d’encoder le cache JSON.');
        }

        if (file_put_contents($this->path, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’écrire le cache : ' . $this->path);
        }
    }
}
