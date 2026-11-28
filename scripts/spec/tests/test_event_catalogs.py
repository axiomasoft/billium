"""D63: a catalog cannot silently diverge from the envelope or payload schema."""
import json
import shutil
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from export_common import DEFAULT_REVISION, ExportError, Revision


class EventCatalogTest(unittest.TestCase):
    def test_published_catalog_is_unchanged_across_revisions(self):
        catalog = Path("event-catalogs/events-2026-10-v2.json")
        published = json.loads((DEFAULT_REVISION.with_name("2026-10") / catalog).read_text())
        current = json.loads((DEFAULT_REVISION / catalog).read_text())
        self.assertEqual(current, published)

    def test_current_catalog_exports(self):
        names = {e.schema for e in Revision(DEFAULT_REVISION).envelopes()}
        self.assertIn("billium.event.v2", names)
        self.assertIn("billium.feed.page.v1", names)

    def test_changed_payload_digest_or_type_set_refuses_export(self):
        for member, value in [("payloadSchemaDigest", "sha256:" + "0" * 64),
                              ("eventType", "payment.other.v1")]:
            with self.subTest(member=member), tempfile.TemporaryDirectory(prefix="billium-event-catalogs-") as temporary:
                revision = Path(temporary) / DEFAULT_REVISION.name
                shutil.copytree(DEFAULT_REVISION, revision)
                path = revision / "event-catalogs/events-2026-10-v2.json"
                catalog = json.loads(path.read_text())
                catalog["events"][0][member] = value
                path.write_text(json.dumps(catalog))
                with self.assertRaises(ExportError):
                    Revision(revision)


if __name__ == "__main__":
    unittest.main()
