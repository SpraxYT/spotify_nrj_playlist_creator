<?php

declare(strict_types=1);

/**
 * Historique local des titres NRJ capturés par le cron (data/nrj_history.json).
 * Remplace le scrape distant bloqué par Cloudflare depuis les IP datacenter.
 */
final class NrjHistoryStore
{
    private const MAX_ENTRIES = 500;

    private string $path;

    /** @var list<array{song_id:string,artist:string,title:string,heard_at:string}> */
    private array $entries = [];

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->load();
    }

    /**
     * @return list<NrjSong> plus récents en premier (promos exclues)
     */
    public function songs(): array
    {
        $out = [];
        foreach ($this->entries as $row) {
            if (NrjClient::isJunk($row['artist'], $row['title'])) {
                continue;
            }
            $out[] = new NrjSong($row['song_id'], $row['artist'], $row['title']);
        }
        return $out;
    }

    /**
     * @return list<array{song_id:string,artist:string,title:string,heard_at:string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Ajoute un titre en tête s’il n’est pas déjà le plus récent.
     * Retourne true si une nouvelle entrée a été écrite.
     */
    public function remember(NrjSong $song): bool
    {
        if (NrjClient::isJunk($song->artist, $song->title)) {
            return false;
        }

        if ($this->entries !== []) {
            $top = $this->entries[0];
            if ($top['song_id'] === $song->songId
                || (
                    str_lower($top['artist']) === str_lower($song->artist)
                    && str_lower($top['title']) === str_lower($song->title)
                )
            ) {
                return false;
            }
        }

        // Dédup globale : remonter l’entrée existante en tête
        $filtered = [];
        foreach ($this->entries as $row) {
            if ($row['song_id'] === $song->songId) {
                continue;
            }
            if (
                str_lower($row['artist']) === str_lower($song->artist)
                && str_lower($row['title']) === str_lower($song->title)
            ) {
                continue;
            }
            $filtered[] = $row;
        }

        array_unshift($filtered, [
            'song_id'  => $song->songId,
            'artist'   => $song->artist,
            'title'    => $song->title,
            'heard_at' => gmdate('c'),
        ]);

        if (count($filtered) > self::MAX_ENTRIES) {
            $filtered = array_slice($filtered, 0, self::MAX_ENTRIES);
        }

        $this->entries = $filtered;
        $this->save();
        return true;
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

        $list = array_is_list($decoded) ? $decoded : ($decoded['songs'] ?? []);
        if (!is_array($list)) {
            return;
        }

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $songId = trim((string) ($row['song_id'] ?? ''));
            $artist = trim((string) ($row['artist'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($songId === '' || $artist === '' || $title === '') {
                continue;
            }
            $this->entries[] = [
                'song_id'  => $songId,
                'artist'   => $artist,
                'title'    => $title,
                'heard_at' => (string) ($row['heard_at'] ?? ''),
            ];
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
            'songs'      => $this->entries,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Impossible d’encoder nrj_history.json');
        }
        if (file_put_contents($this->path, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’écrire ' . $this->path);
        }
    }
}
