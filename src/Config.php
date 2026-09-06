<?php

declare(strict_types=1);

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $root): self
    {
        $path = $root . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException(
                'Fichier config.php introuvable. Copiez config.example.php vers config.php.'
            );
        }

        /** @var mixed $raw */
        $raw = require $path;
        if (!is_array($raw)) {
            throw new RuntimeException('config.php doit retourner un tableau.');
        }

        $required = [
            'SPOTIFY_CLIENT_ID',
            'SPOTIFY_CLIENT_SECRET',
            'SPOTIFY_REDIRECT_URI',
            'SPOTIFY_PLAYLIST_ID',
            'CRON_SECRET',
        ];

        $missing = [];
        foreach ($required as $key) {
            $val = $raw[$key] ?? '';
            if ($val === '' || $val === null) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Clés manquantes dans config.php : ' . implode(', ', $missing)
            );
        }

        if (!isset($raw['NRJ_WEBRADIO_ID']) || $raw['NRJ_WEBRADIO_ID'] === '') {
            $raw['NRJ_WEBRADIO_ID'] = '158';
        }

        return new self($raw);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function string(string $key): string
    {
        return (string) $this->get($key, '');
    }
}
