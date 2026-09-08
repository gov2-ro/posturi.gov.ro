"""One place that maps a posting URL to its HTML cache filename, and back.

`fetch-anunturi.py` names each cached page after its URL; `parse-anunturi.py`
reconstructs the URL from that filename to join back to the index CSV. Those two
halves have to agree, and when they silently disagreed the cost was 80 postings
that fetched into a single colliding `index.html` and parsed into nothing —
they reached the database with no body, no attachment and no `expires_at`, which
in turn hid them from the live site because the SQLite export drops NULL
expiries. See docs/activity-log.md, 2026-09-08.

The encoder is `slug_for_url`. The decoder does *not* try to invert it with a
second regex — two regexes that must stay in sync is what caused the bug. It
looks the slug up in a map built from the index CSV by running the encoder over
known URLs, so the two directions agree by construction, and only falls back to
reconstructing a `/joburi/{slug}/` URL for a cached file the index has never
heard of.
"""

from __future__ import annotations

import re
from urllib.parse import urlparse

BASE_URL = "https://posturi.gov.ro"

_UNSAFE = re.compile(r"[^A-Za-z0-9_-]+")


def slug_for_url(url: str) -> str:
    """Filename-safe, unique cache key for a posting URL.

    Most postings are `/joburi/{slug}/` (or the pre-redesign `/anunt/{slug}/`)
    and keep their own slug. Some cards link the raw WordPress permalink
    instead — `/?post_type=pg_job&p=26404` — which has an *empty* path; those
    fall back to the sanitised query string so they stay distinct from each
    other rather than all collapsing onto one name.
    """
    parsed = urlparse(url)
    path = parsed.path.strip("/")
    if path:
        return path.split("/")[-1]
    if parsed.query:
        return _UNSAFE.sub("-", parsed.query).strip("-")
    return "index"


def build_slug_index(urls) -> dict[str, str]:
    """Map cache slug → source URL for every URL the index CSV knows.

    Later duplicates do not overwrite earlier ones: a slug collision here means
    two postings would share a cache file, and the first is the one that owns it.
    """
    index: dict[str, str] = {}
    for url in urls:
        if url:
            index.setdefault(slug_for_url(url), url)
    return index


def url_from_slug(slug: str, slug_index: dict[str, str] | None = None) -> str:
    """Reconstruct a posting URL from its cache slug.

    Prefers the real URL from `slug_index`; falls back to the `/joburi/{slug}/`
    shape, which is right for every ordinary posting and is what the pre-fix
    parser always assumed.
    """
    if slug_index:
        url = slug_index.get(slug)
        if url:
            return url
    return f"{BASE_URL}/joburi/{slug}/"
