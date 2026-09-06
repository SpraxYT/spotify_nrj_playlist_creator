"""Client pour récupérer le titrage NRJ (historique + titre en cours)."""

from __future__ import annotations

import hashlib
import logging
import re
import time
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlencode

import requests

logger = logging.getLogger(__name__)

NRJ_API_URL = "https://www.nrj.fr/api/webradios/get-by-ids"
NRJ_HISTORY_URL = "https://www.nrj.fr/chansons-diffusees"
DEFAULT_TIMEOUT = 15

_USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)

_ITEM_SPLIT = re.compile(r'<div class="listPlaylist-item">')
_ARTIST_RE = re.compile(
    r'c-card-playlist__title[^>]*>\s*([^<]+?)\s*</strong>',
    re.DOTALL,
)
_TITLE_RE = re.compile(r'<span class="description">([^<]*)</span>')
_CLIP_RE = re.compile(r'data-clip="([^"]*)"')


@dataclass(frozen=True)
class NrjSong:
    song_id: str
    artist: str
    title: str

    def display(self) -> str:
        return f"{self.artist} - {self.title}"


class NrjClientError(Exception):
    """Erreur lors de l'appel ou du parsing de l'API / page NRJ."""


def _headers(*, accept: str) -> dict[str, str]:
    return {
        "Accept": accept,
        "User-Agent": _USER_AGENT,
        "Referer": "https://www.nrj.fr/",
        "Origin": "https://www.nrj.fr",
        "Cache-Control": "no-cache",
        "Pragma": "no-cache",
    }


def _should_skip(artist: str, title: str) -> bool:
    combined = f"{artist} {title}".lower()
    if not artist.strip() or not title.strip():
        return True
    # Ignore les jingles NRJ purs (artiste = NRJ et titre très court / générique)
    if artist.strip().upper() == "NRJ" and len(title.strip()) < 3:
        return True
    for kw in ("publicité", "jingle pub", "spot pub", "pub nrj"):
        if kw in combined:
            return True
    return False


def _stable_song_id(*, artist: str, title: str, clip: str | None) -> str:
    """Identifiant stable pour le cache (clip HTML ou empreinte artiste/titre)."""
    if clip:
        return f"clip:{clip}"
    digest = hashlib.sha1(
        f"{artist.casefold().strip()}|{title.casefold().strip()}".encode("utf-8")
    ).hexdigest()[:16]
    return f"hist:{digest}"


def _parse_history_html(html: str) -> list[NrjSong]:
    blocks = _ITEM_SPLIT.split(html)[1:]
    songs: list[NrjSong] = []
    seen_ids: set[str] = set()

    for block in blocks:
        artist_m = _ARTIST_RE.search(block)
        title_m = _TITLE_RE.search(block)
        if not artist_m or not title_m:
            continue

        artist = artist_m.group(1).strip()
        title = title_m.group(1).strip()
        if _should_skip(artist, title):
            continue

        clip_m = _CLIP_RE.search(block)
        clip = (clip_m.group(1).strip() if clip_m else "") or None
        song_id = _stable_song_id(artist=artist, title=title, clip=clip)

        if song_id in seen_ids:
            continue
        seen_ids.add(song_id)
        songs.append(NrjSong(song_id=song_id, artist=artist, title=title))

    return songs


def fetch_recent_songs(webradio_id: str | int = 158) -> list[NrjSong]:
    """
    Récupère l'historique récent depuis la page « chansons diffusées ».

    Plus fiable que l'API webradio (souvent bloquée sur un titre / timestamps
    incohérents). Un paramètre anti-cache évite le CDN (max-age ~10 min).
    """
    webradio_id = str(webradio_id)
    params = {"webradio": webradio_id, "_": str(int(time.time()))}
    url = f"{NRJ_HISTORY_URL}?{urlencode(params)}"

    try:
        response = requests.get(
            url,
            headers=_headers(accept="text/html,application/xhtml+xml"),
            timeout=DEFAULT_TIMEOUT,
        )
    except requests.RequestException as exc:
        raise NrjClientError(f"Échec du scrape NRJ (historique) : {exc}") from exc

    if response.status_code != 200:
        raise NrjClientError(
            f"HTTP {response.status_code} sur chansons-diffusees : "
            f"{response.text[:200]}"
        )

    songs = _parse_history_html(response.text)
    if not songs:
        raise NrjClientError(
            "Aucun titre parsé sur chansons-diffusees "
            "(structure HTML peut-être changée)."
        )
    return songs


def _fetch_current_from_api(webradio_id: str) -> NrjSong | None:
    params = {"ids[]": webradio_id}
    try:
        response = requests.get(
            NRJ_API_URL,
            params=params,
            headers=_headers(accept="application/json"),
            timeout=DEFAULT_TIMEOUT,
        )
    except requests.RequestException as exc:
        raise NrjClientError(f"Échec de la requête NRJ API : {exc}") from exc

    if response.status_code != 200:
        raise NrjClientError(
            f"HTTP {response.status_code} depuis NRJ API : {response.text[:200]}"
        )

    try:
        data: dict[str, Any] = response.json()
    except ValueError as exc:
        raise NrjClientError("Réponse NRJ API non JSON") from exc

    station = data.get(webradio_id)
    if not isinstance(station, dict):
        raise NrjClientError(
            f"Webradio {webradio_id} absente de la réponse : {list(data.keys())}"
        )

    playlist = station.get("playlist") or []
    if not playlist:
        return None

    entry = playlist[0]
    song = entry.get("song") if isinstance(entry, dict) else None
    if not isinstance(song, dict):
        return None

    end_ts = entry.get("end_timestamp")
    if isinstance(end_ts, (int, float)) and end_ts < time.time() - 30:
        logger.debug(
            "API webradio périmée (end_timestamp=%.0f < now), ignorée.",
            end_ts,
        )
        return None

    song_id = song.get("id")
    artist = (song.get("artist") or "").strip()
    title = (song.get("title") or "").strip()

    if song_id is None or _should_skip(artist, title):
        return None

    return NrjSong(song_id=str(song_id), artist=artist, title=title)


def fetch_current_song(webradio_id: str | int = 158) -> NrjSong | None:
    """
    Retourne le morceau en cours.

    Source principale : premier titre de l'historique HTML (à jour).
    Fallback : API webradio, uniquement si son end_timestamp n'est pas dépassé.
    """
    webradio_id = str(webradio_id)

    try:
        recent = fetch_recent_songs(webradio_id)
        return recent[0]
    except NrjClientError as exc:
        logger.warning(
            "Historique HTML indisponible (%s) — fallback API webradio.",
            exc,
        )

    return _fetch_current_from_api(webradio_id)
