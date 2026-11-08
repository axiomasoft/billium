import importlib.util
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

SPEC = importlib.util.spec_from_file_location('acceptance_groups', Path(__file__).resolve().parents[1] / 'acceptance-groups.py')
assert SPEC and SPEC.loader
LINT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(LINT)


class AcceptanceGroupTests(unittest.TestCase):
    def test_phase_and_owner_are_exact_tokens(self):
        catalog = '\n'.join([
            '| PF-10 | test | billium | P3, P9 | req |',
            '| ACC-RST-10 | test | billium | P3 | req |',
            '| PF-11 | test | billium | P30 | req |',
            '| PF-12 | test | neighbour | P3 | req |',
        ])
        self.assertEqual({'PF-10', 'ACC-RST-10'}, LINT.required_ids(catalog, 'P3'))

    def test_missing_group_is_red_including_commented_attributes(self):
        with TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'docs').mkdir()
            (root / 'docs/acceptance-catalog.md').write_text('| PF-10 | test | billium | P3 | req |')
            tests = root / 'packages/core/modules/platform/tests'
            tests.mkdir(parents=True)
            test = tests / 'ProofTest.php'
            test.write_text("<?php /* #[Group('PF-10')] */\n// #[Group('PF-10')]\n")
            self.assertEqual(({'PF-10'}, {'PF-10'}), LINT.check(root, 'P3'))
            test.write_text("<?php #[Group('PF-10')] final class ProofTest {}")
            self.assertEqual(({'PF-10'}, set()), LINT.check(root, 'P3'))

    def test_fully_qualified_and_double_quoted_group(self):
        self.assertEqual({'PF-10'}, LINT.test_groups('<?php #[\\PHPUnit\\Framework\\Attributes\\Group("PF-10")]'))

    def test_strings_and_documentation_are_not_group_proof(self):
        source = '''<?php
        $example = "#[Group('PF-10')]";
        $fixture = <<<'PHP'
#[Group('PF-12')]
PHP;
        # #[Group('PF-13')]
        #[Group('PF-14')]
        '''
        self.assertEqual({'PF-14'}, LINT.test_groups(source))


if __name__ == '__main__':
    unittest.main()
