# spotify_nrj_playlist_creator

Ajoute automatiquement à une playlist Spotify les titres diffusés sur NRJ.

Le titre en cours est lu via les **métadonnées ICY** du flux `streaming.nrjaudio.fm` (hors Cloudflare), avec repli sur `radio-api.net` puis `www.nrj.fr` en dernier recours. Conçu pour PHP + cron (aaPanel / VPS) : les pages `nrj.fr` sont souvent en **403 Cloudflare** depuis une IP datacenter.

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
| `NRJ_STREAM_URL` | Optionnel — URL du flux ICY (défaut selon webradio) |
| `CRON_SECRET` | Secret pour `run.php` et la page historique (**à régénérer s’il a fuité**) |

## Autorisation (une fois)

Ouvrez `auth.php` dans le navigateur. Spotify redirige, le refresh token est stocké dans `data/token.json`.

## Utilisation

```bash
# Titre en cours (cron) — source ICY, enregistre aussi data/nrj_history.json
php run.php

# Import depuis l’historique local
php run.php --backfill
```

HTTP :

```bash
curl "https://votre-domaine.tld/run.php?key=VOTRE_CRON_SECRET"
```

### Historique (manuel)

Ouvrez `history.php` : liste l’historique **local** construit par le cron, permet de capturer le titre en cours et d’ajouter le lot à Spotify.

Sur un VPS, l’historique distant `chansons-diffusees` est en général inaccessible (Cloudflare). Plus le cron tourne souvent (ex. chaque minute), plus l’historique local se remplit.

Logs : stdout + `data/run.log`. Cache : `data/cache.json`. Historique local : `data/nrj_history.json`.

## Fichiers

| Fichier | Rôle |
|---|---|
| `run.php` | Cron / titre en cours / `--backfill` |
| `history.php` | UI historique miroir → playlist |
| `debug.php` | Diagnostic Spotify (me/owner/scopes/test add) |
| `auth.php` | OAuth Spotify |
| `index.php` | Accueil |
| `src/` | Clients NRJ / Spotify, cache, HTTP |
| `config.example.php` | Modèle de configuration |
| `legacy/python/` | Ancienne version Python |

## Dépannage Spotify (HTTP 403)

Si `history.php` affiche `403 Forbidden` :

1. **Endpoint fév. 2026** — l’ajout doit utiliser `POST /v1/playlists/{id}/items`
   (l’ancien `/tracks` renvoie **403** en Development Mode, même si vous êtes propriétaire).
2. **Development mode** — [Dashboard](https://developer.spotify.com/dashboard) → votre app
   (**NRJ TUBE**) → **User Management** → Add user, puis rouvrez `auth.php`.
3. **Propriétaire** — seulement si `me.id` ≠ `owner.id` (voir `debug.php`).
4. Ouvrez `debug.php?key=CRON_SECRET` : compare me/owner, scopes du token, bouton
   **Test ajout 1 titre**.

Les promos / jingles (ex. « NRJ EURO HOT 30 ») sont filtrées. Le bouton
« Récupérer l’historique NRJ » charge le **miroir complet** (~30–40 titres), fusionné
avec l’historique ICY/local.

## Licence

MIT — voir [LICENSE](LICENSE).
