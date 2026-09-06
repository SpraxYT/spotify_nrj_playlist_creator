"""Client NRJ : titre en cours + historique (API, ICY, radio-api, miroir)."""

from __future__ import annotations

import hashlib
import json
import logging
import re
import time
from dataclasses import dataclass
from typing import Any, Callable
from urllib.parse import urlencode

import requests

logger = logging.getLogger(__name__)

NRJ_API_URL = "https://www.nrj.fr/api/webradios/get-by-ids"
NRJ_HISTORY_URL = "https://www.nrj.fr/chansons-diffusees"
MIRROR_HISTORY_URL = "https://myradioenligne.fr/nrj/playlist"
RADIO_API_NOW = "https://prod.radio-api.net/stations/now-playing?stationIds=nrjfrance"

STREAM_URLS = {
    "158": "https://streaming.nrjaudio.fm/oumvmk8fnozc",
    "1": "https://streaming.nrjaudio.fm/ouuk8j5n3nje",
}

DEFAULT_TIMEOUT = 12
ICY_TIMEOUT = 8
ICY_POST_AD_WAIT_SEC = 22
ICY_POST_AD_INTERVAL = 2.5
ICY_POST_AD_MIN_SEC = 15
ICY_POST_AD_MAX_SEC = 25

_USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36"
)

_ITEM_SPLIT = re.compile(r'<div class="listPlaylist-item">')
_ARTIST_RE = re.compile(
    r"c-card-playlist__title[^>]*>\s*([^<]+?)\s*</strong>",
    re.DOTALL,
)
_TITLE_RE = re.compile(r'<span class="description">([^<]*)</span>')
_CLIP_RE = re.compile(r'data-clip="([^"]*)"')
_MIRROR_RE = re.compile(
    r'<span itemprop="byArtist">\s*([^<]+?)\s*</span>\s*-\s*'
    r'<span itemprop="name">\s*([^<]+?)\s*</span>',
    re.DOTALL,
)
_ARTIST_TITLE_RE = re.compile(r"^(.+?)\s+[-–—]\s+(.+)$", re.UNICODE)
_ARTIST_COLON_RE = re.compile(r"^(.+?)\s*:\s+(.+)$", re.UNICODE)

_ACCENT_MAP = str.maketrans(
    {
        "é": "e",
        "è": "e",
        "ê": "e",
        "ë": "e",
        "à": "a",
        "â": "a",
        "ù": "u",
        "û": "u",
        "ô": "o",
        "î": "i",
        "ï": "i",
        "ç": "c",
    }
)

_PROMO_NEEDLES = (
    "hit music only",
    "euro hot",
    "eurohot",
    "nrj euro",
    "hits les plus diffus",
    "30 hits les plus",
    "les 30 hits",
    "hot 30",
    "jingle",
    "habillage",
    "indicatif",
    "publicite",
    "jingle pub",
    "spot pub",
    "pub nrj",
    "nrj next",
    "webradio",
)


@dataclass(frozen=True)
class NrjSong:
    song_id: str
    artist: str
    title: str

    def display(self) -> str:
        return f"{self.artist} - {self.title}"


class NrjClientError(Exception):
    """Erreur lors de l'appel ou du parsing NRJ / flux."""


StalePredicate = Callable[[NrjSong], bool]


def is_junk(artist: str, title: str) -> bool:
    """Promo / jingle / EURO HOT — à exclure partout."""
    if not artist.strip() or not title.strip():
        return True

    combined = f"{artist} {title}".lower()
    normalized = combined.translate(_ACCENT_MAP)
    compact = re.sub(r"\s+", "", normalized)
    artist_u = artist.strip().upper()
    title_u = title.strip().upper()

    if artist_u == "NRJ" and len(title.strip()) < 3:
        return True

    for kw in _PROMO_NEEDLES:
        kw_n = kw.translate(_ACCENT_MAP)
        if kw_n in normalized or kw_n.replace(" ", "") in compact:
            return True

    if title_u == "NRJ" and "hit music" in combined:
        return True
    if artist_u == "NRJ" and title_u == "NRJ":
        return True
    if artist_u == "NRJ" and len(title.strip()) > 40:
        return True
    if title_u.startswith("NRJ ") and "hit" in combined:
        return True

    return False


def _headers(*, accept: str, referer: str = "https://www.nrj.fr/", origin: str = "https://www.nrj.fr") -> dict[str, str]:
    return {
        "Accept": accept,
        "User-Agent": _USER_AGENT,
        "Referer": referer,
        "Origin": origin,
        "Cache-Control": "no-cache",
        "Pragma": "no-cache",
    }


def _stable_song_id(*, artist: str, title: str, clip: str | None = None) -> str:
    if clip:
        return f"clip:{clip}"
    digest = hashlib.sha1(
        f"{artist.casefold().strip()}|{title.casefold().strip()}".encode("utf-8")
    ).hexdigest()[:16]
    return f"hist:{digest}"


def _parse_artist_title(combined: str) -> tuple[str, str] | None:
    combined = combined.strip()
    if not combined:
        return None
    m = _ARTIST_TITLE_RE.match(combined)
    if m:
        artist, title = m.group(1).strip(), m.group(2).strip()
        if artist and title:
            return artist, title
    m = _ARTIST_COLON_RE.match(combined)
    if m:
        artist, title = m.group(1).strip(), m.group(2).strip()
        if artist and title and "http" not in artist:
            return artist, title
    return None


def _is_stale(song: NrjSong, predicate: StalePredicate | None) -> bool:
    if is_junk(song.artist, song.title):
        return True
    if predicate is None:
        return False
    return bool(predicate(song))


def _http_get(url: str, *, accept: str, referer: str | None = None, origin: str | None = None) -> str:
    try:
        response = requests.get(
            url,
            headers=_headers(
                accept=accept,
                referer=referer or "https://www.nrj.fr/",
                origin=origin or "https://www.nrj.fr",
            ),
            timeout=DEFAULT_TIMEOUT,
        )
    except requests.RequestException as exc:
        raise NrjClientError(f"Échec HTTP : {exc}") from exc

    if response.status_code != 200:
        raise NrjClientError(
            f"HTTP {response.status_code} : {response.text[:160]}"
        )
    return response.text


# ---------------------------------------------------------------------------
# Sources
# ---------------------------------------------------------------------------


def _fetch_from_nrj_api(webradio_id: str) -> NrjSong | None:
    params = {"ids[]": webradio_id}
    try:
        response = requests.get(
            NRJ_API_URL,
            params=params,
            headers=_headers(accept="application/json"),
            timeout=DEFAULT_TIMEOUT,
        )
    except requests.RequestException as exc:
        raise NrjClientError(f"Échec NRJ API : {exc}") from exc

    if response.status_code != 200:
        raise NrjClientError(
            f"HTTP {response.status_code} NRJ API : {response.text[:200]}"
        )

    try:
        data: dict[str, Any] = response.json()
    except ValueError as exc:
        raise NrjClientError("Réponse NRJ API non JSON") from exc

    station = data.get(webradio_id)
    if not isinstance(station, dict):
        raise NrjClientError(f"Webradio {webradio_id} absente : {list(data.keys())}")

    playlist = station.get("playlist") or []
    if not playlist:
        return None

    entry = playlist[0]
    if not isinstance(entry, dict):
        return None

    end_ts = entry.get("end_timestamp")
    if isinstance(end_ts, (int, float)) and end_ts < time.time() - 30:
        logger.info("API webradio périmée (end_timestamp=%.0f), ignorée.", end_ts)
        return None

    song = entry.get("song")
    if not isinstance(song, dict):
        return None

    raw_id = song.get("id")
    artist = (song.get("artist") or "").strip()
    title = (song.get("title") or "").strip()
    if is_junk(artist, title):
        return None

    song_id = (
        str(raw_id)
        if raw_id is not None and str(raw_id) not in ("", "0")
        else _stable_song_id(artist=artist, title=title)
    )
    return NrjSong(song_id=song_id, artist=artist, title=title)


def _fetch_from_radio_api() -> NrjSong | None:
    body = _http_get(
        RADIO_API_NOW,
        accept="application/json",
        referer="https://www.radio.net/",
        origin="https://www.radio.net",
    )
    try:
        data = json.loads(body)
    except ValueError:
        return None
    if not isinstance(data, list) or not data:
        return None
    row = data[0]
    if not isinstance(row, dict):
        return None
    combined = (row.get("title") or "").strip()
    if not combined:
        return None
    parsed = _parse_artist_title(combined)
    if not parsed:
        return None
    artist, title = parsed
    if is_junk(artist, title):
        return None
    return NrjSong(
        song_id=_stable_song_id(artist=artist, title=title),
        artist=artist,
        title=title,
    )


def _extract_stream_title(raw: str) -> str:
    m = re.search(r"StreamTitle='([^;]*)'\s*;", raw, re.DOTALL)
    if m:
        return m.group(1).strip()
    m = re.search(r'StreamTitle="([^;]*)"\s*;', raw, re.DOTALL)
    if m:
        return m.group(1).strip()
    m = re.search(r"StreamTitle=([^;]+)", raw, re.IGNORECASE)
    if m:
        return m.group(1).strip().strip(" \t\"'")
    return ""


def _read_icy_meta(stream_url: str) -> dict[str, Any] | None:
    """Lit icy-metaint + premier bloc StreamTitle / ad."""
    try:
        with requests.get(
            stream_url,
            headers={
                "Icy-MetaData": "1",
                "User-Agent": _USER_AGENT,
                "Connection": "close",
                "Accept": "*/*",
                "Cache-Control": "no-cache",
            },
            stream=True,
            timeout=ICY_TIMEOUT,
        ) as resp:
            meta_int = 0
            for key, val in resp.headers.items():
                if key.lower() == "icy-metaint":
                    meta_int = int(val)
                    break
            if meta_int <= 0:
                raise NrjClientError("Flux sans icy-metaint")

            need = meta_int * 4 + 4096
            buf = bytearray()
            for chunk in resp.iter_content(chunk_size=8192):
                if not chunk:
                    break
                buf.extend(chunk)
                if len(buf) >= need:
                    break
    except requests.RequestException as exc:
        raise NrjClientError(f"Échec lecture ICY : {exc}") from exc

    return _parse_icy_buffer(bytes(buf), meta_int)


def _parse_icy_buffer(buffer: bytes, meta_int: int) -> dict[str, Any] | None:
    offset = 0
    length = len(buffer)
    last_ad: dict[str, Any] | None = None

    for _ in range(10):
        if offset + meta_int >= length:
            break
        offset += meta_int
        if offset >= length:
            break
        length_byte = buffer[offset]
        offset += 1
        meta_len = length_byte * 16
        if meta_len <= 0:
            continue
        if offset + meta_len > length:
            break
        raw = buffer[offset : offset + meta_len].rstrip(b"\x00").decode("utf-8", "replace")
        offset += meta_len
        if not raw:
            continue

        stream_title = _extract_stream_title(raw)
        is_ad = bool(
            re.search(
                r"adw_ad='true'|insertionType='(?:preroll|midroll|ad)'",
                raw,
                re.IGNORECASE,
            )
        )
        ad_duration_ms = 0
        dm = re.search(r"durationMilliseconds='(\d+)'", raw, re.IGNORECASE)
        if dm:
            ad_duration_ms = int(dm.group(1))

        candidate = {
            "stream_title": stream_title.strip(),
            "is_ad": is_ad,
            "raw": raw,
            "ad_duration_ms": ad_duration_ms,
        }
        if is_ad or not candidate["stream_title"]:
            last_ad = candidate
            continue
        return candidate

    return last_ad


def _fetch_from_icy(stream_url: str) -> dict[str, Any]:
    """
    Retourne dict : song, ad_banner, junk, ad_duration_ms.
    """
    empty: dict[str, Any] = {
        "song": None,
        "ad_banner": False,
        "junk": False,
        "ad_duration_ms": 0,
    }
    meta = _read_icy_meta(stream_url)
    if meta is None:
        return empty

    ad_duration_ms = int(meta.get("ad_duration_ms") or 0)
    if meta.get("is_ad"):
        logger.info("ICY méta pub : %s", (meta.get("raw") or "")[:100])
        return {
            "song": None,
            "ad_banner": True,
            "junk": False,
            "ad_duration_ms": ad_duration_ms,
        }

    title_raw = (meta.get("stream_title") or "").strip()
    if not title_raw:
        return empty

    if is_junk("NRJ", title_raw) or is_junk(title_raw, title_raw):
        logger.info("ICY StreamTitle promo : %s", title_raw[:80])
        return {"song": None, "ad_banner": False, "junk": True, "ad_duration_ms": 0}

    parsed = _parse_artist_title(title_raw)
    if not parsed:
        logger.info("ICY StreamTitle non parsé : %s", title_raw[:80])
        return empty

    artist, title = parsed
    if is_junk(artist, title):
        logger.info("ICY StreamTitle junk : %s - %s", artist, title)
        return {"song": None, "ad_banner": False, "junk": True, "ad_duration_ms": 0}

    return {
        "song": NrjSong(
            song_id=_stable_song_id(artist=artist, title=title),
            artist=artist,
            title=title,
        ),
        "ad_banner": False,
        "junk": False,
        "ad_duration_ms": 0,
    }


def _wait_for_icy_after_ad(stream_url: str, ad_duration_ms: int = 0) -> NrjSong | None:
    wait_sec = ICY_POST_AD_WAIT_SEC
    if ad_duration_ms > 0:
        from_ad = int(ad_duration_ms / 1000) + 2
        wait_sec = max(ICY_POST_AD_MIN_SEC, min(ICY_POST_AD_MAX_SEC, from_ad))

    deadline = time.monotonic() + wait_sec
    attempt = 0
    logger.info("ICY : attente StreamTitle post-pub jusqu’à %d s…", wait_sec)

    while time.monotonic() < deadline:
        attempt += 1
        time.sleep(ICY_POST_AD_INTERVAL)
        try:
            icy = _fetch_from_icy(stream_url)
        except NrjClientError as exc:
            logger.warning("ICY essai #%d : %s", attempt, exc)
            continue
        if icy["song"] is not None:
            return icy["song"]
        if icy.get("junk"):
            logger.info("ICY essai #%d : promo ignorée, on continue…", attempt)
            continue
        if icy.get("ad_banner"):
            logger.info("ICY essai #%d : pub toujours en cours…", attempt)

    return None


def _parse_mirror_html(html: str) -> list[NrjSong]:
    songs: list[NrjSong] = []
    seen: set[str] = set()
    for m in _MIRROR_RE.finditer(html):
        artist = m.group(1).strip()
        title = m.group(2).strip()
        if is_junk(artist, title):
            continue
        song_id = _stable_song_id(artist=artist, title=title)
        if song_id in seen:
            continue
        seen.add(song_id)
        songs.append(NrjSong(song_id=song_id, artist=artist, title=title))
    return songs


def _fingerprint(artist: str, title: str) -> str:
    return f"{artist.casefold().strip()}|{title.casefold().strip()}"


def _parse_history_html(html: str, *, unique: bool = True) -> list[NrjSong]:
    """
    Parse la page officielle chansons-diffusees.

    Ordre : plus récent → plus ancien (comme sur le site).
    Si unique=True, déduplique par artiste|titre (rejeux exclus ;
    les rediffusions consécutives ou non n’ajoutent qu’une entrée Spotify).
    """
    blocks = _ITEM_SPLIT.split(html)[1:]
    songs: list[NrjSong] = []
    seen: set[str] = set()
    for block in blocks:
        artist_m = _ARTIST_RE.search(block)
        title_m = _TITLE_RE.search(block)
        if not artist_m or not title_m:
            continue
        artist = artist_m.group(1).strip()
        title = title_m.group(1).strip()
        if is_junk(artist, title):
            continue
        clip_m = _CLIP_RE.search(block)
        clip = (clip_m.group(1).strip() if clip_m else "") or None
        song_id = _stable_song_id(artist=artist, title=title, clip=clip)
        if unique:
            fp = _fingerprint(artist, title)
            if fp in seen:
                continue
            seen.add(fp)
        songs.append(NrjSong(song_id=song_id, artist=artist, title=title))
    return songs


def _merge_unique(primary: list[NrjSong], extra: list[NrjSong]) -> list[NrjSong]:
    """Conserve l’ordre de primary, complète avec les titres absents de extra."""
    seen = {_fingerprint(s.artist, s.title) for s in primary}
    out = list(primary)
    for song in extra:
        fp = _fingerprint(song.artist, song.title)
        if fp in seen:
            continue
        seen.add(fp)
        out.append(song)
    return out


def fetch_recent_from_mirror() -> list[NrjSong]:
    html = _http_get(
        MIRROR_HISTORY_URL,
        accept="text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
        referer="https://myradioenligne.fr/",
        origin="https://myradioenligne.fr",
    )
    songs = _parse_mirror_html(html)
    if not songs:
        raise NrjClientError("Miroir : aucun titre musical parsé")
    logger.info("Historique NRJ via miroir (%d titre(s)).", len(songs))
    return songs


def fetch_chansons_diffusees(webradio_id: str | int = 158) -> list[NrjSong]:
    """Historique complet du jour depuis nrj.fr/chansons-diffusees (IP locale)."""
    webradio_id = str(webradio_id)
    params = {"webradio": webradio_id, "_": str(int(time.time()))}
    url = f"{NRJ_HISTORY_URL}?{urlencode(params)}"
    html = _http_get(url, accept="text/html,application/xhtml+xml")
    # Comptage brut (avec rejeux) pour les logs
    raw_plays = len(_parse_history_html(html, unique=False))
    songs = _parse_history_html(html, unique=True)
    if not songs:
        raise NrjClientError(
            "chansons-diffusees : aucun titre musical parsé "
            "(structure HTML peut-être changée)."
        )
    logger.info(
        "Historique NRJ via chansons-diffusees : %d unique(s) "
        "(%d diffusion(s) brutes, promos exclues).",
        len(songs),
        raw_plays,
    )
    return songs


def fetch_day_history(webradio_id: str | int = 158) -> list[NrjSong]:
    """
    Journée complète pour backfill local :
    1. page officielle chansons-diffusees (historique long)
    2. miroir en complément (titres absents de la page)
    """
    webradio_id = str(webradio_id)
    errors: list[str] = []
    primary: list[NrjSong] = []

    try:
        primary = fetch_chansons_diffusees(webradio_id)
    except NrjClientError as exc:
        errors.append(f"chansons-diffusees : {exc}")
        logger.warning("chansons-diffusees indisponible : %s", exc)

    mirror: list[NrjSong] = []
    try:
        mirror = fetch_recent_from_mirror()
    except NrjClientError as exc:
        errors.append(f"miroir : {exc}")
        logger.warning("Miroir indisponible (complément) : %s", exc)

    if primary and mirror:
        merged = _merge_unique(primary, mirror)
        added = len(merged) - len(primary)
        if added:
            logger.info(
                "Backfill jour : +%d titre(s) via miroir (total %d).",
                added,
                len(merged),
            )
        return merged
    if primary:
        return primary
    if mirror:
        logger.info(
            "Backfill jour : repli miroir seul (%d titre(s)) — "
            "page nrj.fr inaccessible (CF / réseau).",
            len(mirror),
        )
        return mirror

    raise NrjClientError(
        "Historique jour NRJ inaccessible. Détails : " + " | ".join(errors)
    )


def fetch_recent_songs(webradio_id: str | int = 158) -> list[NrjSong]:
    """
    Historique récent pour sync live :
    page officielle d’abord (IP résidentielle), miroir en secours.
    """
    webradio_id = str(webradio_id)
    errors: list[str] = []

    try:
        return fetch_chansons_diffusees(webradio_id)
    except NrjClientError as exc:
        errors.append(f"chansons-diffusees : {exc}")
        logger.warning("Historique chansons-diffusees indisponible : %s", exc)

    try:
        return fetch_recent_from_mirror()
    except NrjClientError as exc:
        errors.append(f"miroir : {exc}")
        logger.warning("Historique miroir indisponible : %s", exc)

    raise NrjClientError(
        "Historique distant NRJ inaccessible. Détails : " + " | ".join(errors)
    )


def fetch_current_song(
    webradio_id: str | int = 158,
    *,
    stream_url: str | None = None,
    is_stale: StalePredicate | None = None,
) -> NrjSong | None:
    """
    Titre en cours (IP résidentielle) :

    1. API officielle NRJ (souvent à jour chez soi)
    2. ICY StreamTitle réel (gagne toujours s’il est musical)
    3. radio-api (rejeté si déjà en playlist / junk)
    4. Attente ICY post-pub si bannière / repli périmé
    5. Miroir : premier titre musical non périmé
    """
    webradio_id = str(webradio_id)
    stream = stream_url or STREAM_URLS.get(webradio_id, STREAM_URLS["158"])
    errors: list[str] = []
    icy_ad = False
    ad_duration_ms = 0
    need_icy_wait = False

    # 1) API NRJ officielle
    try:
        song = _fetch_from_nrj_api(webradio_id)
        if song is not None:
            if _is_stale(song, is_stale):
                need_icy_wait = True
                logger.info(
                    "repli nrj.fr API périmé (déjà en playlist), essai ICY… — %s",
                    song.display(),
                )
                errors.append(f"nrj.fr API : périmé {song.display()}")
            else:
                logger.info("Titre via API nrj.fr : %s", song.display())
                return song
        else:
            errors.append("nrj.fr API : aucun titre valide")
    except NrjClientError as exc:
        errors.append(f"nrj.fr API : {exc}")
        logger.warning("API nrj.fr indisponible : %s", exc)

    # 2) ICY
    try:
        icy = _fetch_from_icy(stream)
        if icy["song"] is not None:
            logger.info("Titre via ICY stream : %s", icy["song"].display())
            return icy["song"]
        if icy.get("junk"):
            errors.append("ICY : titre promo / jingle ignoré")
            logger.info("ICY : StreamTitle promo ignoré (EURO HOT / jingle).")
        elif icy.get("ad_banner"):
            icy_ad = True
            need_icy_wait = True
            ad_duration_ms = int(icy.get("ad_duration_ms") or 0)
            logger.info(
                "ICY : bannière pub — repli radio-api / miroir "
                "(rejet si périmé, puis nouvel essai ICY)."
            )
            errors.append("ICY : bannière pub")
        else:
            errors.append("ICY : aucun titre")
    except NrjClientError as exc:
        errors.append(f"ICY : {exc}")
        logger.warning("ICY indisponible : %s", exc)

    # 3) radio-api
    try:
        radio = _fetch_from_radio_api()
        if radio is not None:
            if _is_stale(radio, is_stale):
                need_icy_wait = True
                logger.info(
                    "repli radio-api périmé (déjà en playlist), nouvel essai ICY… — %s",
                    radio.display(),
                )
                errors.append(f"radio-api : périmé {radio.display()}")
            else:
                src = "radio-api.net (repli pub ICY)" if icy_ad else "radio-api.net"
                logger.info("Titre via %s : %s", src, radio.display())
                return radio
        else:
            errors.append("radio-api : aucun titre valide")
    except NrjClientError as exc:
        errors.append(f"radio-api : {exc}")
        logger.warning("radio-api indisponible : %s", exc)

    # 4) Attente ICY post-pub / après repli périmé
    if need_icy_wait:
        waited = _wait_for_icy_after_ad(stream, ad_duration_ms)
        if waited is not None:
            logger.info("Titre via ICY stream (après pub) : %s", waited.display())
            return waited
        errors.append("ICY : pas de StreamTitle après attente post-pub")

    # 5) Miroir — plus récent non junk / non périmé
    try:
        recent = fetch_recent_songs(webradio_id)
        for song in recent:
            if is_junk(song.artist, song.title):
                continue
            if _is_stale(song, is_stale):
                logger.info(
                    "repli miroir périmé (déjà en playlist), suivant… — %s",
                    song.display(),
                )
                continue
            src = "miroir (repli pub ICY)" if icy_ad else "miroir (fallback)"
            logger.info("Titre via %s : %s", src, song.display())
            return song
        errors.append("miroir : aucun titre musical frais")
    except NrjClientError as exc:
        errors.append(f"miroir : {exc}")

    logger.info("Aucun titre NRJ valide en cours. %s", " | ".join(errors))
    return None
