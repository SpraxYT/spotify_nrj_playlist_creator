"""Client Spotify : auth OAuth, recherche, dédoublonnage et ajout playlist."""

from __future__ import annotations

import json
import logging
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import spotipy
from spotipy.oauth2 import SpotifyOAuth

logger = logging.getLogger(__name__)

SCOPES = (
    "playlist-modify-public "
    "playlist-modify-private "
    "playlist-read-private"
)

FEAT_PATTERN = re.compile(
    r"\s*[\(\[]?\s*(feat\.?|ft\.?|featuring|avec|&)\s+.+?[\)\]]?\s*$",
    re.IGNORECASE,
)
PUNCT_PATTERN = re.compile(r"[^\w\s]", re.UNICODE)


class SpotifyClientError(Exception):
    """Erreur liée à l'API Spotify."""


def normalize_query_part(value: str) -> str:
    """Nettoie artiste/titre pour améliorer la recherche Spotify."""
    cleaned = FEAT_PATTERN.sub("", value)
    cleaned = PUNCT_PATTERN.sub(" ", cleaned)
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    return cleaned


class SpotifyPlaylistSync:
    def __init__(
        self,
        *,
        client_id: str,
        client_secret: str,
        redirect_uri: str,
        playlist_id: str,
        cache_path: Path,
        token_cache_path: Path | None = None,
    ) -> None:
        self.playlist_id = playlist_id
        self.cache_path = cache_path
        self._playlist_uris: set[str] = set()
        self._seen_nrj_ids: set[str] = set()
        self._cache_entries: list[dict[str, Any]] = []

        auth_manager = SpotifyOAuth(
            client_id=client_id,
            client_secret=client_secret,
            redirect_uri=redirect_uri,
            scope=SCOPES,
            cache_path=str(token_cache_path or Path(".cache")),
            open_browser=True,
        )
        self.sp = spotipy.Spotify(auth_manager=auth_manager)

        self._load_local_cache()
        self._refresh_playlist_uris()

    def _load_local_cache(self) -> None:
        if not self.cache_path.exists():
            return
        try:
            raw = json.loads(self.cache_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            logger.warning("Cache local illisible (%s), réinitialisation.", exc)
            return

        entries = raw if isinstance(raw, list) else raw.get("tracks", [])
        if not isinstance(entries, list):
            return

        self._cache_entries = [e for e in entries if isinstance(e, dict)]
        for entry in self._cache_entries:
            nrj_id = entry.get("nrj_song_id")
            uri = entry.get("uri")
            if nrj_id:
                self._seen_nrj_ids.add(str(nrj_id))
            if uri:
                self._playlist_uris.add(str(uri))

    def _save_local_cache(self) -> None:
        self.cache_path.parent.mkdir(parents=True, exist_ok=True)
        payload = {
            "updated_at": datetime.now(timezone.utc).isoformat(),
            "tracks": self._cache_entries,
        }
        self.cache_path.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

    def _refresh_playlist_uris(self) -> None:
        """Charge tous les URIs déjà présents dans la playlist Spotify."""
        logger.info("Chargement des titres existants de la playlist…")
        offset = 0
        limit = 100
        while True:
            try:
                page = self.sp.playlist_items(
                    self.playlist_id,
                    fields="items.track.uri,next",
                    additional_types=["track"],
                    limit=limit,
                    offset=offset,
                )
            except spotipy.SpotifyException as exc:
                raise SpotifyClientError(
                    f"Impossible de lire la playlist : {exc}"
                ) from exc

            items = page.get("items") or []
            for item in items:
                track = item.get("track") or {}
                uri = track.get("uri")
                if uri:
                    self._playlist_uris.add(uri)

            if not page.get("next"):
                break
            offset += limit

        logger.info("%d URI(s) déjà dans la playlist.", len(self._playlist_uris))

    def has_seen_nrj_song(self, nrj_song_id: str) -> bool:
        return str(nrj_song_id) in self._seen_nrj_ids

    def search_track(self, artist: str, title: str) -> dict[str, Any] | None:
        """Recherche un morceau : requête stricte puis fallback libre."""
        artist_q = normalize_query_part(artist)
        title_q = normalize_query_part(title)
        queries = [
            f'track:"{title_q}" artist:"{artist_q}"',
            f"{title_q} {artist_q}",
            f'track:{title_q}',
        ]

        for query in queries:
            try:
                results = self.sp.search(q=query, type="track", limit=5)
            except spotipy.SpotifyException as exc:
                raise SpotifyClientError(f"Recherche Spotify échouée : {exc}") from exc

            items = (results.get("tracks") or {}).get("items") or []
            if not items:
                continue

            # Préfère un match dont l'artiste figure dans le nom
            artist_lower = artist_q.lower()
            for item in items:
                names = " ".join(
                    a.get("name", "") for a in (item.get("artists") or [])
                ).lower()
                if artist_lower and artist_lower.split()[0] in names:
                    return item
            return items[0]

        return None

    def add_track(
        self,
        *,
        nrj_song_id: str,
        artist: str,
        title: str,
    ) -> str:
        """
        Recherche et ajoute le morceau si besoin.

        Returns:
            Statut : "added" | "already" | "not_found"
        """
        nrj_song_id = str(nrj_song_id)

        if self.has_seen_nrj_song(nrj_song_id):
            logger.info("Déjà traité (cache NRJ) : %s - %s", artist, title)
            return "already"

        track = self.search_track(artist, title)
        if not track:
            logger.warning("Introuvable sur Spotify : %s - %s", artist, title)
            # Marquer comme vu pour ne pas retenter à chaque poll
            self._remember(nrj_song_id, None, artist, title, status="not_found")
            return "not_found"

        uri = track["uri"]
        track_name = track.get("name", title)
        track_artists = ", ".join(
            a.get("name", "") for a in (track.get("artists") or [])
        )

        if uri in self._playlist_uris:
            logger.info(
                "Déjà dans la playlist : %s - %s", track_artists, track_name
            )
            self._remember(nrj_song_id, uri, artist, title, status="already")
            return "already"

        try:
            self.sp.playlist_add_items(self.playlist_id, [uri])
        except spotipy.SpotifyException as exc:
            raise SpotifyClientError(
                f"Échec de l'ajout à la playlist : {exc}"
            ) from exc

        self._playlist_uris.add(uri)
        self._remember(nrj_song_id, uri, artist, title, status="added")
        logger.info("Ajouté avec succès : %s - %s (%s)", track_artists, track_name, uri)
        return "added"

    def _remember(
        self,
        nrj_song_id: str,
        uri: str | None,
        artist: str,
        title: str,
        *,
        status: str,
    ) -> None:
        self._seen_nrj_ids.add(nrj_song_id)
        entry = {
            "nrj_song_id": nrj_song_id,
            "uri": uri,
            "artist": artist,
            "title": title,
            "status": status,
            "added_at": datetime.now(timezone.utc).isoformat(),
        }
        self._cache_entries.append(entry)
        self._save_local_cache()
