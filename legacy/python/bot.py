#!/usr/bin/env python3
"""Bot NRJ → Spotify : synchronise le titre en cours vers une playlist."""

from __future__ import annotations

import argparse
import logging
import os
import sys
import time
from pathlib import Path

from dotenv import load_dotenv

from nrj_client import (
    NrjClientError,
    fetch_current_song,
    fetch_recent_songs,
)
from spotify_client import SpotifyClientError, SpotifyPlaylistSync

ROOT = Path(__file__).resolve().parent
CACHE_FILE = ROOT / "data" / "seen_tracks.json"
TOKEN_CACHE = ROOT / ".cache"

MIN_BACKOFF = 5
MAX_BACKOFF = 300

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("nrj-spotify-bot")


def load_config() -> dict[str, str | int]:
    load_dotenv(ROOT / ".env")

    required = (
        "SPOTIFY_CLIENT_ID",
        "SPOTIFY_CLIENT_SECRET",
        "SPOTIFY_REDIRECT_URI",
        "SPOTIFY_PLAYLIST_ID",
    )
    missing = [key for key in required if not os.getenv(key)]
    if missing:
        logger.error(
            "Variables manquantes dans .env : %s. "
            "Copiez .env.example vers .env et renseignez-les.",
            ", ".join(missing),
        )
        sys.exit(1)

    try:
        poll = int(os.getenv("POLL_INTERVAL_SECONDS", "45"))
    except ValueError:
        poll = 45

    return {
        "client_id": os.environ["SPOTIFY_CLIENT_ID"],
        "client_secret": os.environ["SPOTIFY_CLIENT_SECRET"],
        "redirect_uri": os.environ["SPOTIFY_REDIRECT_URI"],
        "playlist_id": os.environ["SPOTIFY_PLAYLIST_ID"],
        "webradio_id": os.getenv("NRJ_WEBRADIO_ID", "158"),
        "poll_interval": max(15, poll),
    }


def process_once(sync: SpotifyPlaylistSync, webradio_id: str) -> None:
    song = fetch_current_song(webradio_id)
    if song is None:
        logger.info("Aucun titre valide en cours (pub / pause).")
        return

    if sync.has_seen_nrj_song(song.song_id):
        logger.info("Toujours en on-air (déjà traité) : %s", song.display())
        return

    logger.info("Nouveau titre NRJ : %s (id=%s)", song.display(), song.song_id)
    status = sync.add_track(
        nrj_song_id=song.song_id,
        artist=song.artist,
        title=song.title,
    )
    logger.info("Résultat : %s", status)


def run_backfill(sync: SpotifyPlaylistSync, webradio_id: str) -> None:
    """Ajoute les titres manquants depuis l'historique NRJ récent."""
    songs = fetch_recent_songs(webradio_id)
    logger.info(
        "Backfill — %d titre(s) unique(s) dans l'historique NRJ.",
        len(songs),
    )

    # Du plus ancien au plus récent pour un ordre playlist plus naturel
    pending = list(reversed(songs))
    counts = {"added": 0, "already": 0, "not_found": 0, "skipped": 0}

    for song in pending:
        if sync.has_seen_nrj_song(song.song_id):
            counts["skipped"] += 1
            logger.info("Déjà traité : %s", song.display())
            continue

        logger.info("Backfill : %s (id=%s)", song.display(), song.song_id)
        status = sync.add_track(
            nrj_song_id=song.song_id,
            artist=song.artist,
            title=song.title,
        )
        counts[status] = counts.get(status, 0) + 1
        # Petit délai pour respecter les rate limits Spotify
        time.sleep(0.35)

    logger.info(
        "Backfill terminé — ajoutés=%d, déjà présents=%d, "
        "introuvables=%d, ignorés(cache)=%d.",
        counts["added"],
        counts["already"],
        counts["not_found"],
        counts["skipped"],
    )


def run_loop(sync: SpotifyPlaylistSync, webradio_id: str, poll_interval: int) -> None:
    backoff = MIN_BACKOFF
    logger.info(
        "Démarrage — webradio=%s, intervalle=%ss "
        "(source : historique HTML NRJ). Ctrl+C pour arrêter.",
        webradio_id,
        poll_interval,
    )

    while True:
        try:
            process_once(sync, webradio_id)
            backoff = MIN_BACKOFF
            time.sleep(poll_interval)
        except KeyboardInterrupt:
            logger.info("Arrêt demandé. Au revoir.")
            break
        except (NrjClientError, SpotifyClientError) as exc:
            logger.error("%s", exc)
            logger.info("Nouvel essai dans %ss…", backoff)
            time.sleep(backoff)
            backoff = min(backoff * 2, MAX_BACKOFF)
        except Exception:
            logger.exception("Erreur inattendue")
            logger.info("Nouvel essai dans %ss…", backoff)
            time.sleep(backoff)
            backoff = min(backoff * 2, MAX_BACKOFF)


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Synchronise le titrage NRJ vers une playlist Spotify."
    )
    parser.add_argument(
        "--once",
        action="store_true",
        help="Exécute un seul cycle puis quitte (idéal pour cron).",
    )
    parser.add_argument(
        "--backfill",
        action="store_true",
        help=(
            "Importe l'historique récent NRJ (page chansons-diffusees) "
            "puis quitte. Réutilise le cache / dédoublonnage Spotify."
        ),
    )
    args = parser.parse_args()

    config = load_config()
    sync = SpotifyPlaylistSync(
        client_id=str(config["client_id"]),
        client_secret=str(config["client_secret"]),
        redirect_uri=str(config["redirect_uri"]),
        playlist_id=str(config["playlist_id"]),
        cache_path=CACHE_FILE,
        token_cache_path=TOKEN_CACHE,
    )

    webradio_id = str(config["webradio_id"])

    if args.backfill:
        try:
            run_backfill(sync, webradio_id)
        except (NrjClientError, SpotifyClientError) as exc:
            logger.error("%s", exc)
            sys.exit(1)
        return

    if args.once:
        try:
            process_once(sync, webradio_id)
        except (NrjClientError, SpotifyClientError) as exc:
            logger.error("%s", exc)
            sys.exit(1)
        return

    run_loop(sync, webradio_id, int(config["poll_interval"]))


if __name__ == "__main__":
    main()
