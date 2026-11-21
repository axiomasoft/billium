#!/usr/bin/env python3
"""Negative checks for the N/N−1 pin. A wrong hash or a forward-compat claim fails."""

from __future__ import annotations

import copy
from pathlib import Path
import tempfile
import unittest

import run


class PinTests(unittest.TestCase):
    def test_forward_compatibility_is_refused(self) -> None:
        matrix = copy.deepcopy(run.load(Path(__file__).with_name("matrix.json")))
        matrix["forwardCompatibility"] = True
        self.assertIn("forward compatibility is not claimed", run.policy_errors(matrix))

    def test_published_release_is_refused(self) -> None:
        matrix = copy.deepcopy(run.load(Path(__file__).with_name("matrix.json")))
        matrix["prior"]["publishedRelease"] = True
        self.assertTrue(any("published release" in item for item in run.policy_errors(matrix)))

    def test_hash_mismatch_fails_closed(self) -> None:
        matrix = copy.deepcopy(run.load(Path(__file__).with_name("matrix.json")))
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            for entry in matrix["prior"]["archives"].values():
                path = base / entry["path"]
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(b"not-the-pinned-bytes")
                entry["sha256"] = "0" * 64
            errors = run.archive_errors(matrix, base)
        self.assertTrue(any("hash mismatch" in item for item in errors))


if __name__ == "__main__":
    unittest.main()
