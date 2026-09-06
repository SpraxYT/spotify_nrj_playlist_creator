# spotify_nrj_playlist_creator

Ajoute automatiquement à une playlist Spotify les titres diffusés sur NRJ.

Le script lit le titrage sur [nrj.fr/chansons-diffusees](https://www.nrj.fr/chansons-diffusees), cherche chaque morceau sur Spotify et l’ajoute sans doublon. Conçu pour PHP + cron (aaPanel ou équivalent).

## Prérequis

- PHP 8.1+ avec extensions `curl` et `json` (recommandé)
- Compte Spotify + [application Developer Dashboard](https://developer.spotify.com/dashboard)
- Une playlist Spotify dont vous êtes propriétaire

## Configuration

1. Créez une app Spotify et récupérez Client ID / Client Secret.
2. Ajoutez une Redirect URI (ex. `https://votre-domaine.tld/auth.php`).
3. Copiez la config :

```bash
cp config.example.php config.php
```

Renseignez `config.php` (ne le committez jamais) :

| Clé | Description |
|---|---|
| `SPOTIFY_*` | Identifiants app + Redirect URI + ID playlist |
| `NRJ_WEBRADIO_ID` | `158` = NRJ FM, `1` = NRJ HITS |
| `CRON_SECRET` | Secret pour `run.php` et la page historique |

## Autorisation (une fois)

Ouvrez `auth.php` dans le navigateur. Spotify redirige, le refresh token est stocké dans `data/token.json` (chemin absolu sous la racine du projet). Le consentement est forcé (`show_dialog=true`).

Si une ré-autorisation n’écrit pas de `refresh_token`, révoquez l’app sur [spotify.com/account/apps](https://www.spotify.com/account/apps/) puis rouvrez `auth.php`. En cas de ré-auth sans nouveau refresh, l’ancien token déjà présent dans `token.json` est conservé.

## Utilisation

```bash
# Titre en cours (cron)
php run.php

# Import historique (CLI)
php run.php --backfill
```

HTTP :

```bash
curl "https://votre-domaine.tld/run.php?key=VOTRE_CRON_SECRET"
```

### Page historique (manuel)

Ouvrez `history.php`, saisissez `CRON_SECRET`, cliquez sur **Récupérer l’historique NRJ**, puis **Ajouter à la playlist Spotify**.

L’accueil `index.php` regroupe les liens (auth, historique, run).

Logs : stdout + `data/run.log`. Cache : `data/cache.json`.

## Fichiers

| Fichier | Rôle |
|---|---|
| `run.php` | Cron / titre en cours / `--backfill` |
| `history.php` | UI web historique → playlist |
| `auth.php` | OAuth Spotify |
| `index.php` | Accueil |
| `src/` | Clients NRJ / Spotify, cache, HTTP |
| `config.example.php` | Modèle de configuration |
| `legacy/python/` | Ancienne version Python |

## Licence

MIT — voir [LICENSE](LICENSE).
