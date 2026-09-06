#!/usr/bin/env python3
"""
Bot local NRJ → Spotify.

Utilise le .env à la racine du projet. PHP reste pour le VPS / cron ;
ce script est prévu pour une machine locale (IP résidentielle).
"""

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
    NrjSong,
    fetch_current_song,
    fetch_recent_songs,
    is_junk,
)
from spotify_client import SpotifyClientError, SpotifyPlaylistSync

LOCAL_BOT_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = LOCAL_BOT_DIR.parent
CACHE_FILE = PROJECT_ROOT / "data" / "seen_tracks.json"
TOKEN_CACHE = PROJECT_ROOT / ".cache"

MIN_BACKOFF = 5
MAX_BACKOFF = 300
MAX_MIRROR_ADDS = 18
RECENT_MIRROR_CHECK = 12

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("nrj-spotify-local")


def load_config() -> dict[str, str | int]:
    # .env projet (racine) puis éventuel local_bot/.env
    load_dotenv(PROJECT_ROOT / ".env")
    load_dotenv(LOCAL_BOT_DIR / ".env")

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
            "Copiez local_bot/.env.example vers la racine (.env).",
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
        "stream_url": os.getenv("NRJ_STREAM_URL") or "",
        "poll_interval": max(15, poll),
    }


def _stale_predicate(sync: SpotifyPlaylistSync):
    def _is_stale(song: NrjSong) -> bool:
        return sync.is_handled_successfully(song.song_id, song.artist, song.title)

    return _is_stale


def _songs_match(a: NrjSong, b: NrjSong) -> bool:
    if a.song_id == b.song_id:
        return True
    return (
        a.artist.casefold() == b.artist.casefold()
        and a.title.casefold() == b.title.casefold()
    )


def add_live(sync: SpotifyPlaylistSync, song: NrjSong) -> None:
    if is_junk(song.artist, song.title):
        logger.info("Ajout live ignoré (promo) : %s", song.display())
        return

    if sync.is_handled_successfully(song.song_id, song.artist, song.title):
        logger.info(
            "Ajout live : déjà en playlist / traité — %s [%s]",
            song.display(),
            song.song_id,
        )
        return

    logger.info("Ajout live : %s [%s]", song.display(), song.song_id)
    status = sync.add_track(
        nrj_song_id=song.song_id,
        artist=song.artist,
        title=song.title,
    )
    logger.info("Ajout live résultat=%s — %s", status, song.display())


def sync_mirror(
    sync: SpotifyPlaylistSync,
    songs: list[NrjSong],
    *,
    skip: NrjSong | None,
    max_adds: int,
    label: str,
) -> None:
    counts = {"added": 0, "already": 0, "not_found": 0, "skipped": 0, "junk": 0}
    adds = 0

    for song in songs:
        if is_junk(song.artist, song.title):
            counts["junk"] += 1
            continue
        if skip is not None and _songs_match(song, skip):
            continue
        if sync.is_handled_successfully(song.song_id, song.artist, song.title):
            counts["skipped"] += 1
            continue
        if adds >= max_adds:
            logger.info(
                "%s : plafond (%d ajouts) — reste au prochain cycle.",
                label,
                max_adds,
            )
            break

        logger.info("%s ajout : %s [%s]", label, song.display(), song.song_id)
        status = sync.add_track(
            nrj_song_id=song.song_id,
            artist=song.artist,
            title=song.title,
        )
        counts[status] = counts.get(status, 0) + 1
        if status == "added":
            adds += 1
        time.sleep(0.35)

    logger.info(
        "%s terminée — ajoutés=%d, déjà=%d, introuvables=%d, skip=%d, promos=%d.",
        label,
        counts["added"],
        counts["already"],
        counts["not_found"],
        counts["skipped"],
        counts["junk"],
    )


def process_once(
    sync: SpotifyPlaylistSync,
    webradio_id: str,
    stream_url: str | None,
) -> None:
    is_stale = _stale_predicate(sync)
    live = fetch_current_song(
        webradio_id,
        stream_url=stream_url or None,
        is_stale=is_stale,
    )

    if live is not None:
        add_live(sync, live)
    else:
        logger.info("Aucun titre live résolu (pub / pause / promo / périmé).")

    remote: list[NrjSong] = []
    try:
        remote = fetch_recent_songs(webradio_id)
        logger.info("Sync miroir : %d titre(s) musicaux récupérés.", len(remote))
    except NrjClientError as exc:
        logger.info("Sync miroir indisponible : %s", exc)

    batch = remote[:RECENT_MIRROR_CHECK]
    if not batch:
        logger.info("Sync miroir : rien de nouveau à examiner.")
        return

    sync_mirror(
        sync,
        batch,
        skip=live,
        max_adds=MAX_MIRROR_ADDS,
        label="Sync miroir",
    )


def run_backfill(sync: SpotifyPlaylistSync, webradio_id: str) -> None:
    songs = fetch_recent_songs(webradio_id)
    logger.info("Backfill — %d titre(s) musicaux (miroir / historique).", len(songs))
    # Du plus ancien au plus récent pour un ordre playlist plus naturel
    pending = list(reversed(songs))
    sync_mirror(
        sync,
        pending,
        skip=None,
        max_adds=50,
        label="Backfill",
    )


def run_loop(
    sync: SpotifyPlaylistSync,
    webradio_id: str,
    stream_url: str | None,
    poll_interval: int,
) -> None:
    backoff = MIN_BACKOFF
    logger.info(
        "Démarrage local — webradio=%s, intervalle=%ss "
        "(API NRJ → ICY → radio-api → miroir). Ctrl+C pour arrêter.",
        webradio_id,
        poll_interval,
    )

    while True:
        try:
            process_once(sync, webradio_id, stream_url)
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
        description="Bot local : synchronise le titrage NRJ vers Spotify."
    )
    parser.add_argument(
        "--once",
        action="store_true",
        help="Un seul cycle (live + sync miroir) puis quitte.",
    )
    parser.add_argument(
        "--backfill",
        action="store_true",
        help="Importe l’historique miroir complet manquant, puis quitte.",
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
    stream_url = str(config["stream_url"]) or None

    if args.backfill:
        try:
            run_backfill(sync, webradio_id)
        except (NrjClientError, SpotifyClientError) as exc:
            logger.error("%s", exc)
            sys.exit(1)
        return

    if args.once:
        try:
            process_once(sync, webradio_id, stream_url)
        except (NrjClientError, SpotifyClientError) as exc:
            logger.error("%s", exc)
            sys.exit(1)
        return

    run_loop(sync, webradio_id, stream_url, int(config["poll_interval"]))


if __name__ == "__main__":
    main()
