"""Wrapper of the vendored cyberphone JCS oracle (RFC 8785); the vendored code is never edited.

canonicalize(text) -> bytes: parse JSON text and return its UTF-8 canonical form.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent))

from org.webpki.json.Canonicalize import canonicalize as _canonicalize  # noqa: E402


def _no_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"duplicate object key: {key!r}")
        result[key] = value
    return result


def _no_constant(name: str) -> Any:
    raise ValueError(f"non-finite number is not JSON: {name}")


def canonicalize(text: str) -> bytes:
    value = json.loads(text, object_pairs_hook=_no_duplicates, parse_constant=_no_constant)
    return _canonicalize(value)
