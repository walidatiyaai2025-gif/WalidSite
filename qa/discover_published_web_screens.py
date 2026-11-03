#!/usr/bin/env python3
"""Discover real, current public WEB-05/WEB-08 pages for 14-screen diagnostics.

Only connects to numeric IPv4 loopback, without credentials, redirects, proxies,
writes or fabricated content. A public API result is a *candidate*, not
independent proof of professional claims or visual reference matching.
"""
from __future__ import annotations

import argparse
import http.client
import json
import re
import sys
from pathlib import Path
from urllib.parse import urlsplit

try:
    from qa.build_web_visual_plan import build_web_plan
except ModuleNotFoundError as exc:
    if exc.name != "qa":
        raise
    from build_web_visual_plan import build_web_plan


MAX_RESPONSE_BYTES = 512 * 1024
SAFE_SLUG = re.compile(r"[A-Za-z0-9][A-Za-z0-9-]{0,199}\Z")
MAX_PAGES = 3


class PublicationDiscoveryError(ValueError):
    pass


def loopback_port(origin: str) -> int:
    """Reject remote, credentialed, ambiguous or proxy-directed origins."""
    if not isinstance(origin, str) or origin != origin.strip():
        raise PublicationDiscoveryError("Supply an exact numeric loopback HTTP origin")
    try:
        parts = urlsplit(origin)
        port = parts.port or 80
    except ValueError as exc:
        raise PublicationDiscoveryError("Invalid loopback HTTP port") from exc
    if (parts.scheme != "http" or parts.hostname != "127.0.0.1"
            or parts.username is not None or parts.password is not None
            or parts.path not in ("", "/") or parts.query or parts.fragment
            or parts.netloc not in ("127.0.0.1", f"127.0.0.1:{port}")
            or not 1 <= port <= 65535):
        raise PublicationDiscoveryError(
            "Public discovery is restricted to http://127.0.0.1[:port]"
        )
    return port


def fetch_local(port: int, path: str, kind: str):
    """Single read-only local request; no HTTP redirect following or cookie jar."""
    if kind not in ("json", "html") or not path.startswith("/") or path.startswith("//"):
        raise PublicationDiscoveryError("Unexpected public runtime request")
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
    try:
        connection.request(
            "GET", path,
            headers={"Accept": "application/json" if kind == "json" else "text/html"},
        )
        response = connection.getresponse()
        if response.status != 200:
            raise PublicationDiscoveryError(
                "Public runtime endpoint unavailable or redirected (HTTP %d)" % response.status
            )
        content_type = (response.getheader("Content-Type") or "").split(";", 1)[0].lower()
        if content_type != ("application/json" if kind == "json" else "text/html"):
            raise PublicationDiscoveryError("Wrong content type from local public runtime")
        payload = response.read(MAX_RESPONSE_BYTES + 1)
    except (OSError, http.client.HTTPException) as exc:
        raise PublicationDiscoveryError("Local Laravel runtime could not be reached") from exc
    finally:
        connection.close()
    if len(payload) > MAX_RESPONSE_BYTES:
        raise PublicationDiscoveryError("Oversized public runtime response")
    if kind == "html":
        if not re.search(rb"<html(?:\s|>)", payload, re.IGNORECASE):
            raise PublicationDiscoveryError("Public HTML detail page is not an HTML document")
        return True
    try:
        parsed = json.loads(payload.decode("utf-8"))
    except (ValueError, UnicodeError) as exc:
        raise PublicationDiscoveryError("Invalid public runtime JSON") from exc
    if not isinstance(parsed, dict):
        raise PublicationDiscoveryError("Public runtime JSON must be an object")
    return parsed


def _candidate_slug(item: object) -> str | None:
    slug = item.get("slug") if isinstance(item, dict) else None
    return slug if isinstance(slug, str) and SAFE_SLUG.fullmatch(slug) else None


def _has_current_proof(detail: object, slug: str, evidence_key: str) -> bool:
    if not isinstance(detail, dict):
        return False
    data = detail.get("data")
    if not isinstance(data, dict) or data.get("slug") != slug:
        return False
    proofs = data.get(evidence_key)
    return isinstance(proofs, list) and bool(proofs) and all(
        isinstance(url, str) and url.startswith("https://") and len(url) <= 2048
        for url in proofs
    )


def _select_public_slug(port: int, locale: str, *, kind: str, fetch) -> str:
    if kind == "projects":
        api_list, api_detail, web_detail, evidence_key = (
            "/api/v1/public/projects", "/api/v1/public/projects/", "/projects/", "evidence_urls"
        )
    else:
        api_list, api_detail, web_detail, evidence_key = (
            "/api/v1/public/articles", "/api/v1/public/articles/", "/blog/", "citations"
        )
    for page in range(1, MAX_PAGES + 1):
        result = fetch(port, f"{api_list}?lang={locale}&page={page}", "json")
        rows = result.get("data") if isinstance(result, dict) else None
        meta = result.get("meta") if isinstance(result, dict) else None
        if not isinstance(rows, list) or not isinstance(meta, dict):
            raise PublicationDiscoveryError("Public runtime list response has no valid data/meta")
        for item in rows:
            slug = _candidate_slug(item)
            if slug is None:
                continue
            detail = fetch(port, f"{api_detail}{slug}?lang={locale}", "json")
            if not _has_current_proof(detail, slug, evidence_key):
                continue
            # A valid JSON record alone does not prove that WEB-05/08 resolves.
            fetch(port, f"{web_detail}{slug}?lang={locale}", "html")
            return slug
        if kind == "articles":
            more = meta.get("has_more_pages")
            if type(more) is not bool:
                raise PublicationDiscoveryError("Invalid article pagination metadata")
            if not more:
                break
        else:
            last = meta.get("last_page")
            if type(last) is not int or last < page:
                raise PublicationDiscoveryError("Invalid project pagination metadata")
            if page >= last:
                break
    raise PublicationDiscoveryError(
        "No currently public %s with usable reviewed proof was found" % kind
    )


def build_runtime_plan(root: Path, origin: str, *, locale: str = "en",
                       dpr: float = 1, timeout: int = 120, fetch=fetch_local) -> dict:
    if locale not in ("en", "ar"):
        raise PublicationDiscoveryError("Locale must be en or ar")
    port = loopback_port(origin)
    project_slug = _select_public_slug(port, locale, kind="projects", fetch=fetch)
    article_slug = _select_public_slug(port, locale, kind="articles", fetch=fetch)
    # The existing canonical plan performs all screen/route/DPR validation.
    return build_web_plan(
        root, project_slug=project_slug, article_slug=article_slug,
        locale=locale, dpr=dpr, timeout=timeout, require_all=True,
    )


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--origin", required=True,
                        help="Running Laravel at http://127.0.0.1:8000 (no remote discovery)")
    parser.add_argument("--locale", choices=("en", "ar"), default="en")
    parser.add_argument("--dpr", type=float, default=1.0)
    parser.add_argument("--timeout", type=int, default=120)
    parser.add_argument("--output", type=Path, required=True,
                        help="New absolute JSON plan outside the Git checkout")
    args = parser.parse_args(argv)
    try:
        plan = build_runtime_plan(
            Path(__file__).resolve().parents[1], args.origin,
            locale=args.locale, dpr=args.dpr, timeout=args.timeout,
        )
        if not args.output.is_absolute() or args.output.exists() or args.output.is_symlink():
            raise PublicationDiscoveryError("Plan output must be a fresh absolute path")
        with args.output.open("x", encoding="utf-8") as destination:
            json.dump(plan, destination, ensure_ascii=False, indent=2)
            destination.write("\n")
    except (OSError, ValueError) as exc:
        print("LIVE WEB PLAN NOT AVAILABLE: " + str(exc), file=sys.stderr)
        return 2
    print("14 canonical Web diagnostic routes selected from current public Laravel data")
    return 0


if __name__ == "__main__":
    sys.exit(main())
