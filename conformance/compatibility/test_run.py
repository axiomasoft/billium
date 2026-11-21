#!/usr/bin/env python3
"""Negative checks for the N/N−1 pin. A wrong hash or a forward-compat claim fails."""

from __future__ import annotations

import copy
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

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

    def test_prior_must_name_the_historical_commit(self) -> None:
        matrix = copy.deepcopy(run.load(Path(__file__).with_name("matrix.json")))
        matrix["prior"]["commit"] = "HEAD"
        self.assertIn("prior snapshot must pin the P2 closure commit", run.policy_errors(matrix))

    def test_archives_preserve_historical_codec_and_registry(self) -> None:
        matrix = run.load(run.HERE / "matrix.json")
        subjects = {
            "php-contracts": ["src/Contracts/Codec.php", run.ENVELOPES],
            "dart-contracts": ["lib/src/codec.dart", "lib/src/generated/spec_data.g.dart"],
        }
        for name, files in subjects.items():
            with self.subTest(name=name), zipfile.ZipFile(run.HERE / matrix["prior"]["archives"][name]["path"]) as archive:
                for file in files:
                    expected = subprocess.check_output(
                        ["git", "show", f"{run.P2_COMMIT}:{run.CONTRACT_PACKAGES[name]}/{file}"], cwd=run.ROOT,
                    )
                    self.assertEqual(expected, archive.read(file), file)

    def test_current_registry_cannot_stand_in_for_the_prior_registry(self) -> None:
        matrix = run.load(run.HERE / "matrix.json")
        with tempfile.TemporaryDirectory() as temporary:
            archive = Path(temporary) / "fake-prior.zip"
            with zipfile.ZipFile(archive, "w") as package:
                package.write(run.ROOT / run.CONTRACT_PACKAGES["php-contracts"] / run.ENVELOPES, run.ENVELOPES)
            errors = run.schema_errors(archive, set(matrix["currentOnlySchemas"]))
        self.assertTrue(any("current-only schemas differ" in error for error in errors))

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


class CorpusExpectationsTest(unittest.TestCase):
    def test_paginated_queries_require_current_success_and_historical_validation_refusal(self) -> None:
        matrix = run.load(run.HERE / "matrix.json")
        name = "HTTP-15/own"
        expected = matrix["corpusExpectations"][name]
        prepared = {"outcome": "prepared", "prepared": {"schema": "billium.query.v1"}, "attempts": []}
        refused = {"outcome": "refused", "error": {"code": "billium.validation.invalid", "receipt": "none"}, "attempts": []}
        unsupported = {**refused, "error": {"code": "billium.schema.unsupported", "receipt": "none"}}

        self.assertEqual([], run.compare_corpus_generations(prepared, refused, name, expected))
        self.assertTrue(run.compare_corpus_generations(prepared, refused, name, None))
        for current, prior in ((refused, refused), (prepared, prepared), (prepared, unsupported)):
            with self.subTest(current=current, prior=prior):
                self.assertTrue(run.compare_corpus_generations(current, prior, name, expected))


if __name__ == "__main__":
    unittest.main()
