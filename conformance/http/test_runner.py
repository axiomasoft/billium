"""Negative controls for the P8.5 transcript and evidence boundary."""

import copy
import unittest

from jsonschema import ValidationError

from run import REQUIRED, corpus, validator, verify


class TranscriptTest(unittest.TestCase):
    def test_required_inventory_has_no_skippable_cases(self):
        cases = corpus()
        self.assertEqual(set(cases), set(REQUIRED))
        for case in cases.values():
            skipped = {**case, "steps": []}
            with self.assertRaises(ValidationError):
                validator("transcript").validate(skipped)

    def test_equal_business_body_cannot_stand_for_equal_prepared_bytes(self):
        with self.assertRaisesRegex(ValueError, "requestDigest"):
            verify({"wire": {"requestDigest": "different-json-order"}}, {"wire.requestDigest": "original"}, "replay")

    def test_double_result_cannot_be_relabelled_as_loopback(self):
        descriptor = validator("report").schema["properties"]["evidence"]["items"]
        check = type(validator("report"))(descriptor)
        proof = {"boundary": "double", "evidence_class": "provider-double", "steps": ["HTTP-04/provider"]}
        check.validate(proof)
        for change in ({"boundary": "loopback"}, {"evidence_class": "server-runtime"}, {"evidence_class": "provider-live"}):
            with self.assertRaises(ValidationError):
                check.validate({**proof, **change})

    def test_single_case_cannot_claim_required_suite_pass(self):
        sha = "sha256:" + "1" * 64
        edge = {"boundary": "loopback", "evidence_class": "server-runtime", "steps": ["HTTP-01/live"]}
        report = {
            "protocol": "billium.http.transcript.v1", "client": "php", "suite": "HTTP-01",
            "required": list(REQUIRED), "runtime": {"php": "8.4.27", "postgres": "PostgreSQL 16.15"},
            "hashes": dict.fromkeys(("spec", "fixtures", "harness", "packages", "dependencies"), sha),
            "results": [{"id": "HTTP-01", "status": "pass", "profile": "ledger", "sources": ["D59"],
                         "transcript": [{"step": "live", "action": "send", "observed": {"status": 200},
                                         "boundary": edge["boundary"], "evidence_class": edge["evidence_class"]}]}],
            "evidence": [edge, {"boundary": "loopback", "evidence_class": "transport-contract", "steps": []},
                         {"boundary": "double", "evidence_class": "provider-double", "steps": []},
                         {"boundary": "double", "evidence_class": "host-double", "steps": []}],
            "passed": True, "requiredSuitePassed": False,
        }
        check = validator("report")
        check.validate(report)
        fake = copy.deepcopy(report)
        fake.update(suite="all", requiredSuitePassed=True)
        with self.assertRaises(ValidationError):
            check.validate(fake)


if __name__ == "__main__":
    unittest.main()
