#!/usr/bin/env python3
"""D21/D45/D60 migration checks. Static checks supplement live runtime-role proofs."""
from __future__ import annotations

import argparse
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NAME = re.compile(r"^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$")
PROTECTED = {
    'billium_' + name for name in (
        'obligation', 'adjustment', 'customer_credit', 'settlement_intent', 'settlement_target',
        'settlement_intent_closure', 'invoice', 'invoice_line', 'observed_payment',
        'observed_payment_revision', 'payment', 'payment_revision', 'allocation',
        'allocation_receipt', 'credit_note', 'ledger_discrepancy', 'discrepancy_event', 'observation_park',
    )
}


def uncomment(source: str) -> str:
    # Keep quoted PHP/SQL strings, including URLs and SQL literals, intact.
    return re.sub(r"('(?:\\.|''|[^'\\])*'|\"(?:\\.|\"\"|[^\"\\])*\")|/\*.*?\*/|//[^\n]*|--[^\n]*|\#(?!\[)[^\n]*",
                  lambda m: m[1] if m[1] is not None else "\n" * m[0].count("\n"), source, flags=re.S)


def lint(path: Path, source: str) -> list[str]:
    code = uncomment(source)
    errors: list[str] = []

    def reject(condition: object, message: str) -> None:
        if condition:
            errors.append(message)

    reject(not NAME.fullmatch(path.stem), "migration basename must be YYYY_MM_DD_HHMMSS_snake_case")
    reject(re.search(r"->\s*enum\s*\(|CREATE\s+TYPE\b[^;]*\bAS\s+ENUM\b", code, re.I), "statuses require text + CHECK, not enum")
    down = re.search(r"function\s+down\s*\([^)]*\)\s*(?::\s*\w+)?\s*\{(.*?)\}", code, re.S)
    reject(down is None or not re.fullmatch(r"\s*throw\s+new\s+\\?LogicException\s*\(.*?\)\s*;\s*", down[1], re.S),
           "down() must only throw new LogicException (no destructive rollback)")
    table = r'(?:"?public"?\s*\.\s*)?"?([a-zA-Z_][a-zA-Z_0-9]*)"?'
    for pattern in [r"\b(?:CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|ALTER\s+TABLE\s+(?:ONLY\s+)?)" + table,
                    r"Schema::(?:create|table)\s*\(\s*['\"]([^'\"]+)"]:
        for match in re.finditer(pattern, code, re.I):
            reject(not match[1].startswith("billium_"), f"table {match[1]} requires billium_ prefix")

    alters = list(re.finditer(r"\bALTER\s+TABLE\b.*?;", code, re.I | re.S))
    adds_constraint = False
    validates = bool(re.search(r"\bVALIDATE\s+CONSTRAINT\b", code, re.I))
    for alter in alters:
        # PostgreSQL ADD clauses end at the next ALTER action, never a comma inside CHECK/FK.
        for add in re.finditer(r"\bADD\s+(?!COLUMN\b)(?:CONSTRAINT\s+\w+\s+)?(?:CHECK\s*\(|FOREIGN\s+KEY\s*\().*?(?=,\s*(?:ADD|DROP|ALTER|RENAME)\b|;)", alter[0], re.I | re.S):
            adds_constraint = True
            reject(not re.search(r"\bNOT\s+VALID\b", add[0], re.I), "existing-table CHECK/FK must be NOT VALID")
        if re.search(r"\bADD\s+(?:COLUMN\s+)?\w+\s+[^;]*\b(?:CHECK\s*\(|REFERENCES\b)", alter[0], re.I | re.S):
            # Inline ADD COLUMN constraints cannot use PostgreSQL NOT VALID: use a separate ADD CONSTRAINT.
            reject(not re.search(r"\bADD\s+(?:CONSTRAINT\s+\w+\s+)?(?:CHECK|FOREIGN)\b", alter[0], re.I),
                   "ADD COLUMN CHECK/FK must use a separate NOT VALID constraint")
    schema_table = bool(re.search(r"Schema::table\s*\(", code))
    reject(schema_table and re.search(r"->\s*(?:check|foreign|constrained)\s*\(", code),
           "Schema::table CHECK/FK requires explicit SQL NOT VALID")
    reject(adds_constraint and validates, "VALIDATE CONSTRAINT belongs in a separate migration")
    adds_column = bool(re.search(r"\bADD\s+(?:COLUMN\s+)?(?!CONSTRAINT\b|CHECK\b|FOREIGN\b|UNIQUE\b|PRIMARY\b|EXCLUDE\b)\w+\s+\w+", code, re.I) or
                       (schema_table and re.search(r"->\s*(?:addColumn|string|text|integer|bigInteger|boolean|timestamp|timestamps|unsignedBigInteger|foreignId|uuid|json|jsonb|decimal|date|dateTime|binary|char|smallInteger|tinyInteger)\s*\(", code)))
    # TYPE must be the action on this column, not a word in a later SQL statement.
    column = r'(?:"(?:[^"]|"")+"|[a-zA-Z_][a-zA-Z_0-9]*)'
    contracts = bool(re.search(
        r"->\s*(?:dropColumn|renameColumn|change)\s*\(|\b(?:DROP\s+COLUMN|RENAME\s+COLUMN)\b|"
        r"\bALTER\s+COLUMN\s+" + column + r"\s+(?:SET\s+DATA\s+)?TYPE\b", code, re.I))
    reject(adds_column and contracts, "expand and contract column changes require separate migrations")

    for helper in re.finditer(r"RuntimeGrants::grant\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]", code):
        privileges, target = helper.groups()
        reject(target.removeprefix('public.') in PROTECTED and re.search(r"\b(?:ALL|INSERT|UPDATE|DELETE|TRUNCATE|REFERENCES|TRIGGER)\b", privileges, re.I),
               "RuntimeGrants cannot grant protected raw DML")

    for grant in re.finditer(r"\bGRANT\s+(.*?)\s+ON\s+(.*?)\s+TO\s+([^;]+);", code, re.I | re.S):
        privileges, target, role = grant.groups()
        protected = bool(PROTECTED.intersection(re.findall(r'\bbillium_\w+', target)) or re.search(r"\b(?:ALL\s+)?TABLES\b", target, re.I))
        reject(protected and not re.match(r"\s*(?:FUNCTION|PROCEDURE|SEQUENCE)\b", target, re.I)
               and re.search(r"\b(?:ALL|INSERT|UPDATE|DELETE|TRUNCATE|REFERENCES|TRIGGER)\b", privileges, re.I),
               "protected tables cannot grant raw DML; grant SELECT/guarded EXECUTE only")
        reject(re.search(r"\b(?:FUNCTION|PROCEDURE)\b", target, re.I) and re.search(r"\bPUBLIC\b", role, re.I),
               "routine EXECUTE must not be granted to PUBLIC")
        reject(re.search(r"\bSCHEMA\b", target, re.I) and re.search(r"\b(?:ALL|CREATE)\b", privileges, re.I),
               "runtime must not receive schema CREATE powers")
    reject(re.search(r"\bOWNER\s+TO\s+[\"']?\w*runtime\w*", code, re.I), "runtime must not be schema/table/routine owner")
    reject(re.search(r"\bGRANT\s+[\"']?(?:\w*owner\w*|pg_write_all_data)[\"']?\s+TO\b", code, re.I), "runtime must not inherit owner/write-all powers")
    for role in re.finditer(r"\b(?:CREATE|ALTER)\s+ROLE\s+[\"']?\w*runtime\w*[\"']?\s+([^;]+);", code, re.I):
        reject(re.search(r"\b(?:SUPERUSER|CREATEDB|CREATEROLE|REPLICATION|BYPASSRLS)\b", role[1], re.I),
               "runtime role cannot receive privileged role attributes")
    for routine in re.finditer(r"\bCREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION\b.*?\bAS\s+\$(?:[a-zA-Z_]\w*)?\$", code, re.I | re.S):
        if re.search(r"\bSECURITY\s+DEFINER\b", routine[0], re.I):
            reject(not re.search(r"SET\s+search_path\s*=\s*pg_catalog\s*,\s*pg_temp\s+AS", routine[0], re.I),
                   "SECURITY DEFINER needs fixed search_path = pg_catalog, pg_temp")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("paths", nargs="*", type=Path)
    args = parser.parse_args()
    paths = args.paths or sorted((ROOT / "packages/core/database/migrations").rglob("*.php"))
    errors = [f"{path}: {error}" for path in paths for error in lint(path, path.read_text())]
    if not paths:
        errors.append("migrations: no migration files found")
    for error in errors:
        print(error)
    print(f"migrations: {len(paths)} files, {len(errors)} errors")
    return int(bool(errors))


if __name__ == "__main__":
    raise SystemExit(main())
