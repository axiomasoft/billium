import importlib.util
from pathlib import Path
import unittest

SPEC = importlib.util.spec_from_file_location('migrations', Path(__file__).resolve().parents[1] / 'migrations.py')
assert SPEC and SPEC.loader
LINT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(LINT)
NAME = Path('2026_10_29_000001_expand_billium_test.php')


class MigrationTests(unittest.TestCase):
    def source(self, sql):
        return "<?php\nfunction up(): void {\n" + sql + "\n}\nfunction down(): never {\nthrow new LogicException('forward only');\n}\n"

    def errors(self, sql):
        return LINT.lint(NAME, self.source(sql))

    def test_expand_not_valid_and_separate_validate(self):
        self.assertEqual([], self.errors('ALTER TABLE public.billium_test ADD COLUMN note text; ALTER TABLE billium_test ADD CONSTRAINT note_ok CHECK (length(note) < 64) NOT VALID;'))
        self.assertEqual([], self.errors('ALTER TABLE billium_test VALIDATE CONSTRAINT note_ok;'))
        self.assertEqual([], LINT.lint(NAME, '<?php function down(): never { throw new LogicException("forward only"); }'))
        self.assertEqual([], self.errors("CREATE TABLE billium_test (status text CHECK (status IN ('ok')));"))

    def test_enum_and_rollback_are_rejected(self):
        for sql in ["$t->enum('status', ['ok']);", "CREATE TYPE state AS ENUM ('ok');"]:
            self.assertTrue(self.errors(sql))
        self.assertTrue(LINT.lint(NAME, '<?php function down(): void {\nSchema::drop("billium_test");\n}'))
        self.assertTrue(LINT.lint(NAME, self.source('').replace('throw new LogicException', 'return; throw new LogicException')))

    def test_existing_constraints_must_be_not_valid_and_validate_separate(self):
        for sql in [
            'ALTER TABLE billium_test ADD CONSTRAINT positive CHECK (n > 0);',
            'ALTER TABLE billium_test ADD FOREIGN KEY (other) REFERENCES billium_other(id);',
            'ALTER TABLE billium_test ADD COLUMN n integer CHECK (n > 0);',
            'ALTER TABLE billium_test ADD CONSTRAINT c CHECK (n > 0) NOT VALID; ALTER TABLE billium_test VALIDATE CONSTRAINT c;',
            "Schema::table('billium_test', function ($t) { $t->foreign('other'); });",
            'ALTER TABLE billium_test ADD CONSTRAINT c CHECK (n > 0) NOT VALID, ADD CONSTRAINT d CHECK (m > 0);',
        ]:
            with self.subTest(sql=sql):
                self.assertTrue(self.errors(sql))

    def test_expand_contract_name_and_prefix(self):
        for sql in [
            'ALTER TABLE billium_test ADD COLUMN note text; ALTER TABLE billium_test DROP COLUMN old;',
            'ALTER TABLE billium_test ADD note text; ALTER TABLE billium_test DROP COLUMN old;',
            "Schema::table('billium_test', function ($t) { $t->string('note'); $t->renameColumn('a', 'b'); });",
            'CREATE TABLE host_table (id bigint);',
            "Schema::create('host_table', fn ($t) => null);",
        ]:
            self.assertTrue(self.errors(sql))
        self.assertTrue(LINT.lint(Path('bad.php'), self.source('')))

    def test_set_not_null_does_not_read_type_from_a_later_statement(self):
        self.assertEqual([], self.errors('''
            ALTER TABLE billium_test ADD COLUMN source_scope text;
            ALTER TABLE billium_test ALTER COLUMN source_scope SET NOT NULL;
            CREATE TABLE billium_contract (type text);
        '''))
        for action in ['TYPE varchar(64)', 'SET DATA TYPE varchar(64)']:
            with self.subTest(action=action):
                self.assertIn('expand and contract column changes require separate migrations', self.errors(
                    'ALTER TABLE billium_test ADD COLUMN source_scope text; '
                    f'ALTER TABLE billium_test ALTER COLUMN source_scope {action};'))

    def test_event_protocol_migration_obeys_expand_contract_rules(self):
        path = LINT.ROOT / 'packages/core/database/migrations/platform/2026_10_28_000011_create_billium_event_protocol.php'
        self.assertEqual([], LINT.lint(path, path.read_text()))

    def test_runtime_role_and_routine_boundary(self):
        for sql in [
            'GRANT INSERT, UPDATE ON TABLE public.billium_allocation TO billium_runtime;',
            'GRANT UPDATE (amount_minor) ON billium_payment TO billium_runtime;',
            'GRANT UPDATE (compared_at) ON public.billium_refund_reconcile_progress TO billium_runtime;',
            'ALTER DEFAULT PRIVILEGES GRANT ALL ON TABLES TO billium_runtime;',
            'GRANT ALL ON ALL TABLES IN SCHEMA public TO billium_runtime;',
            'ALTER SCHEMA public OWNER TO billium_runtime;',
            'ALTER TABLE billium_payment OWNER TO "billium_runtime";',
            'GRANT CREATE ON SCHEMA public TO billium_runtime;',
            'GRANT billium_owner TO billium_runtime;',
            'CREATE ROLE billium_runtime LOGIN SUPERUSER;',
            "RuntimeGrants::grant('SELECT, INSERT', 'public.billium_allocation');",
            'GRANT EXECUTE ON FUNCTION public.billium_allocate(integer) TO PUBLIC;',
            'CREATE FUNCTION billium_bad() RETURNS void LANGUAGE sql SECURITY DEFINER AS $$ SELECT 1 $$;',
            'CREATE FUNCTION billium_bad() RETURNS void LANGUAGE sql SECURITY DEFINER AS $body$ SELECT 1 $body$;',
            'CREATE FUNCTION billium_bad() RETURNS void LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$ SELECT 1 $$;',
        ]:
            with self.subTest(sql=sql):
                self.assertTrue(self.errors(sql))
        self.assertEqual([], self.errors('GRANT SELECT ON public.billium_payment TO billium_runtime; GRANT EXECUTE ON FUNCTION public.billium_allocate(integer) TO billium_runtime;'))
        self.assertEqual([], self.errors("RuntimeGrants::grant('SELECT, INSERT', 'public.billium_money_commit_log'); CREATE ROLE billium_runtime NOSUPERUSER NOCREATEDB NOCREATEROLE;"))

    def test_comments_are_not_rules(self):
        self.assertEqual([], self.errors('// $t->enum(\n/* CREATE TYPE x AS ENUM; */\n-- ALTER TABLE host ADD CHECK (x);\nCREATE TABLE billium_test (note text);'))

    def test_duplicate_migration_prefixes_are_rejected_across_directories(self):
        same = [
            'documents/2026_11_20_000005_add_render_blob_deleting_state.php',
            'collection/2026_11_20_000005_create_billium_collection_notification_signal_and_intervention_tables.php',
        ]
        errors = LINT.duplicate_prefixes(same)
        self.assertTrue(errors)
        self.assertIn('2026_11_20_000005', errors[0])
        self.assertEqual([], LINT.duplicate_prefixes([
            Path('documents/2026_11_20_000006_add_render_blob_deleting_state.php'),
            'collection/2026_11_20_000005_create_billium_collection_notification_signal_and_intervention_tables.php',
        ]))
        root = LINT.ROOT / 'packages/core/database/migrations'
        self.assertEqual([], LINT.duplicate_prefixes(sorted(root.rglob('*.php'))))


if __name__ == '__main__':
    unittest.main()
