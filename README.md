# spotify_nrj_playlist_creator

Ajoute automatiquement à une playlist Spotify les titres diffusés sur NRJ.

Le bot lit le titrage sur [nrj.fr/chansons-diffusees](https://www.nrj.fr/chansons-diffusees), cherche chaque morceau sur Spotify et l’ajoute sans doublon.

## Prérequis

- Python 3.10+
- Compte Spotify + [application Developer Dashboard](https://developer.spotify.com/dashboard)
- Une playlist Spotify dont vous êtes propriétaire

## Configuration Spotify

1. Créez une app sur le Dashboard Spotify et récupérez le Client ID / Client Secret.
2. Ajoutez la Redirect URI : `http://127.0.0.1:8888/callback`
3. Récupérez l’ID de playlist dans l’URL :

   `https://open.spotify.com/playlist/`**`XXXXXXXX`**

## Installation

```bash
git clone git@github.com:SpraxYT/spotify_nrj_playlist_creator.git
cd spotify_nrj_playlist_creator
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
```

Renseignez `.env` (ne committez jamais ce fichier) :

```env
SPOTIFY_CLIENT_ID=...
SPOTIFY_CLIENT_SECRET=...
SPOTIFY_REDIRECT_URI=http://127.0.0.1:8888/callback
SPOTIFY_PLAYLIST_ID=...
NRJ_WEBRADIO_ID=158
POLL_INTERVAL_SECONDS=45
```

| Variable | Description |
|---|---|
| `NRJ_WEBRADIO_ID` | `158` = NRJ FM, `1` = NRJ HITS |
| `POLL_INTERVAL_SECONDS` | Intervalle entre deux vérifications (minimum 15 s) |

## Utilisation

Au premier lancement, Spotipy ouvre le navigateur pour l’autorisation OAuth. Le token est stocké dans `.cache`.

```bash
# Boucle continue
python bot.py

# Un seul cycle (cron / test)
python bot.py --once

# Import de l'historique récent NRJ, puis arrêt
python bot.py --backfill
```

`--backfill` ajoute les titres présents sur la page d’historique qui manquent encore dans la playlist. Ensuite, lancez `python bot.py` pour suivre le live.

Le fichier `data/seen_tracks.json` mémorise les titres déjà traités.

## Fichiers

| Fichier | Rôle |
|---|---|
| `bot.py` | Point d’entrée (boucle, `--once`, `--backfill`) |
| `nrj_client.py` | Lecture du titrage / historique NRJ |
| `spotify_client.py` | OAuth, recherche, dédoublonnage, ajout playlist |
| `.env.example` | Modèle de configuration |

## Licence

MIT — voir [LICENSE](LICENSE).
