import importlib.util
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

TOOLS = Path(__file__).resolve().parents[2] / 'spec'
sys.path.insert(0, str(TOOLS))
SPEC = importlib.util.spec_from_file_location('product_identities', TOOLS / 'identities.py')
assert SPEC and SPEC.loader
IDENTITIES = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(IDENTITIES)


class ProductIdentityTests(unittest.TestCase):
    def test_reads_files_instead_of_module_declarations_and_excludes_drill(self):
        with TemporaryDirectory() as directory:
            root = Path(directory)
            for rel in ['packages/core/database/migrations/platform/2026_10_29_000001_test.php',
                        'packages/core/tests/Drill/migrations/2026_10_29_000002_drill.php']:
                path = root / rel
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('<?php')
            with patch.object(IDENTITIES.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, '{"runner":{"root_deadline_ms":30000},"modules":{"enabled":["platform","ledger"]},"connection":null}', '')):
                found = IDENTITIES.product_identities(root)
            self.assertEqual({
                ('migration', '2026_10_29_000001_test'): 'packages/core/database/migrations/platform/2026_10_29_000001_test.php',
                ('config-key', 'billium.runner.root_deadline_ms'): 'packages/core/config/billium.php',
                ('config-key', 'billium.modules.enabled'): 'packages/core/config/billium.php',
                ('config-key', 'billium.connection'): 'packages/core/config/billium.php',
            }, found)

    def test_removed_identity_is_red_and_existing_identity_keeps_since_and_status(self):
        old = [{'kind': 'migration', 'id': 'first', 'since': '2026-09', 'status': 'frozen', 'source': 'spec/modules.json'}]
        with self.assertRaises(IDENTITIES.IdentityError):
            IDENTITIES.merge({}, old)
        merged = IDENTITIES.merge({('migration', 'first'): {'since': '2026-10', 'source': 'packages/core/database/migrations/first.php'}}, old)
        self.assertEqual('2026-09', merged[0]['since'])
        self.assertEqual('frozen', merged[0]['status'])
        self.assertEqual('packages/core/database/migrations/first.php', merged[0]['source'])

    def test_duplicate_migration_basename_is_not_silently_overwritten(self):
        with TemporaryDirectory() as directory:
            root = Path(directory)
            for module in ['platform', 'ledger']:
                path = root / 'packages/core/database/migrations' / module / '2026_10_29_000001_same.php'
                path.parent.mkdir(parents=True)
                path.write_text('<?php')
            with self.assertRaisesRegex(IDENTITIES.IdentityError, 'duplicate migration identity'):
                IDENTITIES.product_identities(root)


if __name__ == '__main__':
    unittest.main()
