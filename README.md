# spotify_nrj_playlist_creator

Ajoute automatiquement à une playlist Spotify les titres diffusés sur NRJ.

Deux modes :

| | **Python local** (`local_bot/`) | **PHP VPS** (`run.php`) |
|---|---|---|
| Usage | Machine chez vous (IP résidentielle) | Cron / aaPanel / serveur |
| Now-playing | API NRJ → ICY → radio-api → miroir | ICY → radio-api → miroir (nrj.fr souvent CF) |
| Historique / backfill | **chansons-diffusees** (journée ~100+ uniques) + miroir | Miroir seul (~30–40, CF bloque nrj.fr) |
| Config | `.env` à la racine | `config.php` |

Les promos (« NRJ EURO HOT 30 », etc.) sont filtrées. Un repli déjà en playlist
est traité comme **périmé** : nouvel essai ICY après la pub.

## Bot Python local (recommandé chez soi)

```bash
cd "/home/aymcode/Bureau/AYMCODE/SPOTIFY BOT"

# Venv (recréer si besoin)
python3 -m venv .venv --without-pip   # si pip absent dans le venv
# puis : curl -sS https://bootstrap.pypa.io/get-pip.py | .venv/bin/python
# ou simplement :
python3 -m venv .venv

source .venv/bin/activate
pip install -r local_bot/requirements.txt

# .env à la racine (SPOTIFY_*, NRJ_WEBRADIO_ID, POLL_INTERVAL_SECONDS)
cp local_bot/.env.example .env   # si pas encore de .env

# Une passe
python local_bot/bot.py --once

# Journée NRJ → titres manquants (chansons-diffusees + miroir)
python local_bot/bot.py --backfill
# alias :
python local_bot/bot.py --backfill-day

# Compter sans toucher Spotify
python local_bot/bot.py --probe-history

# Boucle
python local_bot/bot.py

# Raccourci
./run_local.sh --once
```

Cache : `data/seen_tracks.json`. Token OAuth : `.cache` à la racine.

## Bot PHP (VPS / cron)

Prérequis : PHP 8.1+ (`curl`, `json`), playlist Spotify dont vous êtes propriétaire.

```bash
cp config.example.php config.php
# renseigner SPOTIFY_*, CRON_SECRET, NRJ_WEBRADIO_ID
php run.php          # live + sync miroir
php run.php --backfill
```

Cron recommandé (chaque minute) :

```cron
* * * * * php /chemin/vers/run.php >/dev/null 2>&1
```

Auth une fois via `auth.php`. UI historique : `history.php`.
Logs : `data/run.log`. Cache : `data/cache.json`.

Pendant une **bannière pub** ICY, un titre radio-api déjà en playlist est rejeté
(`repli radio-api périmé…`) puis le bot attend un vrai StreamTitle (~15–25 s).

## Dépannage Spotify (HTTP 403)

1. Endpoint fév. 2026 : `POST /v1/playlists/{id}/items` (pas `/tracks`).
2. Development mode → User Management → Add user, puis `auth.php`.
3. `debug.php?key=CRON_SECRET` pour me/owner / test d’ajout.

## Licence

MIT — voir [LICENSE](LICENSE).
