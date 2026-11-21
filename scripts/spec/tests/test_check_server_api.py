"""Negative examples for the server API map.

A document placeholder and a status that keeps a stored receipt must fail closed.
"""
from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/spec/check-server-api.py"


def load():
    spec = importlib.util.spec_from_file_location("check_server_api", SCRIPT)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class CheckServerApiTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.api = load()

    def test_document_placeholder_is_rejected(self) -> None:
        reason = self.api.placeholder_rejection({
            "path": "/billium/v1/sellers/{sellerRef}/documents/issue",
            "request": "document.issue.v1",
        })
        self.assertIsNotNone(reason)
        self.assertIn("document", reason)

    def test_status_rules_follow_the_registry(self) -> None:
        status = self.api.problem_http_status
        self.assertEqual(status({"code": "billium.auth.unauthenticated", "category": "auth", "retryable": False, "sameKey": True, "receipt": "none"}), 401)
        self.assertEqual(status({"code": "billium.auth.denied", "category": "auth", "retryable": False, "sameKey": True, "receipt": "none"}), 403)
        self.assertEqual(status({"code": "billium.validation.invalid", "category": "validation", "retryable": False, "sameKey": True, "receipt": "none"}), 400)
        self.assertEqual(status({"code": "billium.recovery.required", "category": "recovery", "retryable": False, "sameKey": True, "receipt": "none"}), 423)
        self.assertEqual(status({"code": "billium.idempotency.in_flight", "category": "idempotency", "retryable": True, "sameKey": True, "receipt": "none"}), 503)
        self.assertEqual(status({"code": "billium.module.unavailable", "category": "module", "retryable": False, "sameKey": True, "receipt": "none"}), 409)
        self.assertEqual(status({"code": "billium.refund.capacity_exceeded", "category": "refund", "retryable": False, "sameKey": False, "receipt": "stored"}), 409)
        self.assertNotEqual(status({"code": "billium.ingress.throttled", "category": "ingress", "retryable": True, "sameKey": True, "receipt": "none"}), 429)

    def test_stored_receipt_cannot_use_a_validation_status(self) -> None:
        errors: list[str] = []
        self.api.check_status_rules([
            {"code": "billium.invoice.frozen", "category": "invoice", "retryable": False, "sameKey": False, "receipt": "stored"},
        ], errors)
        self.assertEqual(errors, [])
        errors.clear()
        self.api.check_status_rules([
            {"code": "billium.validation.invalid", "category": "validation", "retryable": False, "sameKey": True, "receipt": "stored"},
        ], errors)
        self.assertTrue(errors)


if __name__ == "__main__":
    unittest.main()
