#!/usr/bin/env python3
"""Lightweight PostgreSQL query-plan verification for Billium hot paths.

Runs deterministic EXPLAIN (FORMAT JSON) checks inside a rolled-back transaction using a compact
multi-seller dataset and `SET LOCAL enable_seqscan = off` so key index usage and bounded-limit
query shapes are verified quickly without a heavy load generator.
"""

from __future__ import annotations

import argparse
import datetime
import json
import os
import subprocess
import sys
from typing import Any


EXPECTED_INDEXES: list[str] = [
    "billium_obligation_account_due_idx",
    "billium_obligation_due_idx",
    "billium_payment_ref_idx",
    "billium_allocation_obligation_idx",
    "billium_observed_payment_refund_original_idx",
    "billium_ledger_discrepancy_observed_idx",
    "billium_discrepancy_event_discrepancy_idx",
    "billium_allocation_receipt_digest_idx",
    "billium_allocation_receipt_ref_idx",
    "billium_observation_owner_binding_pkey",
    "billium_idempotency_receipt_scope_key",
    "billium_idempotency_opaque_receipt_scope",
    "billium_outbox_event_scope_cursor",
    "billium_outbox_event_seller_cursor",
    "billium_outbox_delivery_due",
    "billium_outbox_delivery_open_event",
    "billium_inbox_message_pending_op_idx",
    "billium_execution_dispatch_work_lookup",
    "billium_execution_provider_work_lookup",
    "billium_execution_provider_reference",
    "billium_execution_business_identity_digest_unique",
    "billium_execution_recon_item_review_idx",
    "billium_execution_operation_root_idx",
    "billium_collection_work_due_idx",
    "billium_schedule_occurrence_amendment_lookup",
]


def build_query_specs() -> list[dict[str, Any]]:
    scope_json = (
        '{"environment":"test","incarnation":"1",'
        '"installationRef":"inst-bench","sellerRef":"bench-seller-a"}'
    )
    financial_scope = (
        '{"environment":"test","installationRef":"inst-bench",'
        '"intentOwnerNamespace":"remote-billium","sellerRef":"bench-seller-a",'
        '"sourceAuthority":"source-a"}'
    )
    return [
        {
            "id": "ledger.due_obligations_account",
            "category": "ledger",
            "table": "billium_obligation",
            "expectedIndexes": ["billium_obligation_account_due_idx"],
            "forbidSort": True,
            "boundedLimit": 51,
            "sql": (
                "SELECT id, obligation_ref, due_at, open_minor FROM public.billium_obligation "
                "WHERE installation_ref = 'inst-bench' AND seller_ref = 'bench-seller-a' "
                "AND environment = 'test' AND billing_account_ref = 'acct-a' "
                "AND status = 'open' AND open_minor > 0 AND due_at IS NOT NULL "
                "AND due_at <= now() ORDER BY due_at, id LIMIT 51"
            ),
        },
        {
            "id": "ledger.due_obligations_seller",
            "category": "ledger",
            "table": "billium_obligation",
            "expectedIndexes": ["billium_obligation_due_idx"],
            "forbidSort": True,
            "boundedLimit": 50,
            "sql": (
                "SELECT id, obligation_ref, due_at FROM public.billium_obligation "
                "WHERE seller_ref = 'bench-seller-a' AND environment = 'test' "
                "AND status = 'open' AND due_at IS NOT NULL AND due_at <= now() "
                "ORDER BY due_at, id LIMIT 50"
            ),
        },
        {
            "id": "ledger.payment_by_ref",
            "category": "ledger",
            "table": "billium_payment",
            "expectedIndexes": ["billium_payment_ref_idx"],
            "forbidSort": False,
            "boundedLimit": 1,
            "sql": (
                "SELECT id, payment_ref, captured_minor FROM public.billium_payment "
                "WHERE installation_ref = 'inst-bench' AND seller_ref = 'bench-seller-a' "
                "AND environment = 'test' AND payment_ref = 'pay-a-1' LIMIT 1"
            ),
        },
        {
            "id": "ledger.allocations_by_obligation",
            "category": "ledger",
            "table": "billium_allocation",
            "expectedIndexes": ["billium_allocation_obligation_idx"],
            "forbidSort": True,
            "boundedLimit": 50,
            "sql": (
                "SELECT id, amount_minor, receipt_ref FROM public.billium_allocation "
                "WHERE seller_ref = 'bench-seller-a' AND environment = 'test' "
                "AND obligation_id = 42 ORDER BY id LIMIT 50"
            ),
        },
        {
            "id": "ledger.refunds_of_charge",
            "category": "ledger",
            "table": "billium_observed_payment",
            "expectedIndexes": ["billium_observed_payment_refund_original_idx"],
            "forbidSort": False,
            "boundedLimit": 50,
            "sql": (
                "SELECT id FROM public.billium_observed_payment "
                "WHERE seller_ref = 'bench-seller-a' AND environment = 'test' "
                "AND source_authority = 'provider-a' AND original_ref = 'pay-a-1' AND kind = 'refund' LIMIT 50"
            ),
        },
        {
            "id": "ledger.discrepancies_of_observed_payment",
            "category": "ledger",
            "table": "billium_ledger_discrepancy",
            "expectedIndexes": ["billium_ledger_discrepancy_observed_idx"],
            "forbidSort": False,
            "boundedLimit": 50,
            "sql": (
                "SELECT id FROM public.billium_ledger_discrepancy "
                "WHERE seller_ref = 'bench-seller-a' AND environment = 'test' AND observed_payment_id = 42 LIMIT 50"
            ),
        },
        {
            "id": "ledger.discrepancy_resolution",
            "category": "ledger",
            "table": "billium_discrepancy_event",
            "expectedIndexes": ["billium_discrepancy_event_discrepancy_idx"],
            "forbidSort": False,
            "boundedLimit": 1,
            "sql": (
                "SELECT 1 FROM public.billium_discrepancy_event "
                "WHERE discrepancy_id = 42 AND kind = 'resolved' LIMIT 1"
            ),
        },
        {
            "id": "ledger.refund_detail_by_digest",
            "category": "ledger",
            "table": "billium_allocation_receipt",
            "expectedIndexes": ["billium_allocation_receipt_digest_idx"],
            "forbidSort": False,
            "boundedLimit": 1,
            "sql": (
                "SELECT receipt_ref FROM public.billium_allocation_receipt "
                "WHERE seller_ref = 'bench-seller-a' AND environment = 'test' "
                "AND semantic_digest = 'sha256:bench-digest' AND kind = 'refund_detail' LIMIT 1"
            ),
        },
        {
            "id": "ledger.allocation_by_receipt_ref",
            "category": "ledger",
            "table": "billium_allocation",
            "expectedIndexes": ["billium_allocation_receipt_ref_idx"],
            "forbidSort": False,
            "boundedLimit": None,
            "sql": (
                "SELECT id, obligation_id FROM public.billium_allocation "
                "WHERE seller_ref = 'bench-seller-a' AND environment = 'test' "
                "AND receipt_ref = 'allocation:bench-a' AND reverses_id IS NULL AND amount_minor > 0"
            ),
        },
        {
            "id": "ledger.observation_owner_binding",
            "category": "ledger",
            "table": "billium_observation_owner_binding",
            "expectedIndexes": ["billium_observation_owner_binding_pkey"],
            "forbidSort": False,
            "boundedLimit": 1,
            "sql": (
                "SELECT 1 FROM public.billium_observation_owner_binding WHERE operation_key = "
                "encode(sha256(convert_to(jsonb_build_array('bench-seller-a', 'test', 'provider-a', 'op-a-1')::text, 'UTF8')), 'hex') "
                "LIMIT 1"
            ),
        },
        {
            "id": "idempotency.receipt_by_command_key",
            "category": "idempotency",
            "table": "billium_idempotency_receipt",
            "expectedIndexes": ["billium_idempotency_receipt_scope_key"],
            "forbidSort": False,
            "boundedLimit": 1,
            "sql": (
                "SELECT id, outcome, digest FROM public.billium_idempotency_receipt "
                "WHERE environment = 'test' AND seller_ref = 'bench-seller-a' "
                "AND command_kind = 'payment.execute.v1' "
                "AND command_key = 'bench-cmd-bench-seller-a-1' LIMIT 1"
            ),
        },
        {
            "id": "idempotency.opaque_receipt_lookup",
            "category": "idempotency",
            "table": "billium_idempotency_receipt",
            "expectedIndexes": ["billium_idempotency_opaque_receipt_scope"],
            "forbidSort": True,
            "boundedLimit": 1,
            "sql": (
                "SELECT id, result FROM public.billium_idempotency_receipt "
                f"WHERE financial_scope = '{financial_scope}'::jsonb "
                "AND outcome = 'accepted' AND result->>'class' = 'execution.operation' "
                "AND result#>>'{body,receiptRef}' = 'bench-rcpt-bench-seller-a-1' "
                "ORDER BY created_at, id LIMIT 1"
            ),
        },
        {
            "id": "outbox.feed_scope_page",
            "category": "outbox",
            "table": "billium_outbox_event",
            "expectedIndexes": [
                "billium_outbox_event_scope_cursor",
                "billium_outbox_event_seller_cursor",
            ],
            "forbidSort": True,
            "boundedLimit": 10,
            "sql": (
                "SELECT id, event_id, tx_id FROM public.billium_outbox_event "
                "WHERE seller_ref = 'bench-seller-a' AND environment = 'test' "
                f"AND source_scope::jsonb = '{scope_json}'::jsonb "
                "AND (tx_id, id) > ('0'::xid8, 0::bigint) "
                "AND tx_id < pg_snapshot_xmin(pg_current_snapshot()) "
                "ORDER BY tx_id, id LIMIT 10"
            ),
        },
        {
            "id": "outbox.relay_due_claim",
            "category": "outbox",
            "table": "billium_outbox_delivery",
            "expectedIndexes": ["billium_outbox_delivery_due"],
            "forbidSort": True,
            "boundedLimit": 10,
            "sql": (
                "SELECT d.id, d.event_id, e.type FROM public.billium_outbox_delivery d "
                "JOIN public.billium_outbox_event e ON e.event_id = d.event_id "
                "WHERE d.status IN ('pending', 'in_flight') "
                "AND d.available_at <= statement_timestamp() "
                "AND (d.status = 'pending' OR d.claim_until <= statement_timestamp()) "
                "ORDER BY d.available_at, d.id LIMIT 10 FOR UPDATE OF d SKIP LOCKED"
            ),
        },
        {
            "id": "outbox.prune_open_deliveries",
            "category": "outbox",
            "table": "billium_outbox_delivery",
            "expectedIndexes": ["billium_outbox_delivery_open_event"],
            "forbidSort": False,
            "boundedLimit": 50,
            "sql": (
                "SELECT id, event_id FROM public.billium_outbox_delivery "
                "WHERE status <> 'delivered' AND event_id = 'bench-evt-bench-seller-a-1' LIMIT 50"
            ),
        },
        {
            "id": "inbox.pending_operation_lookup",
            "category": "inbox",
            "table": "billium_inbox_message",
            "expectedIndexes": ["billium_inbox_message_pending_op_idx"],
            "forbidSort": True,
            "boundedLimit": 1,
            "sql": (
                "SELECT id, operation_revision FROM public.billium_inbox_message "
                "WHERE seller_ref = 'bench-seller-a' AND environment = 'test' "
                "AND channel = 'observation' AND source_authority = 'remote-billium' "
                "AND operation_ref = 'op-a-1' AND payload->>'installationRef' = 'inst-bench' "
                "AND status IN ('ready', 'parked_gap', 'parked_dependency') "
                "ORDER BY operation_revision DESC, id DESC LIMIT 1"
            ),
        },
        {
            "id": "schedule.occurrence_amendment",
            "category": "schedule",
            "table": "billium_schedule_occurrence_amendment",
            "expectedIndexes": ["billium_schedule_occurrence_amendment_lookup"],
            "forbidSort": False,
            "boundedLimit": 1,
            "sql": (
                "SELECT 1 FROM public.billium_schedule_occurrence_amendment "
                "WHERE seller_ref = 'bench-seller-a' AND environment = 'test' "
                "AND occurrence_ref = 'occurrence-a-1' AND schedule_revision = 2 LIMIT 1"
            ),
        },
        {
            "id": "execution.dispatch_work_due",
            "category": "execution",
            "table": "billium_execution_dispatch_work",
            "expectedIndexes": ["billium_execution_dispatch_work_lookup"],
            "forbidSort": True,
            "boundedLimit": 50,
            "sql": (
                "SELECT id, work_ref FROM public.billium_execution_dispatch_work "
                "WHERE installation_ref = 'inst-bench' AND seller_ref = 'bench-seller-a' "
                "AND environment = 'test' AND state = 'due' AND due_at <= now() "
                "ORDER BY due_at, id LIMIT 50"
            ),
        },
        {
            "id": "execution.provider_work_due",
            "category": "execution",
            "table": "billium_execution_provider_work",
            "expectedIndexes": ["billium_execution_provider_work_lookup"],
            "forbidSort": True,
            "boundedLimit": 50,
            "sql": (
                "SELECT id, work_ref FROM public.billium_execution_provider_work "
                "WHERE installation_ref = 'inst-bench' AND seller_ref = 'bench-seller-a' "
                "AND environment = 'test' AND state = 'due' AND due_at <= now() "
                "ORDER BY due_at, id LIMIT 50"
            ),
        },
        {
            "id": "execution.attempt_provider_ref",
            "category": "execution",
            "table": "billium_execution_attempt",
            "expectedIndexes": ["billium_execution_provider_reference"],
            "forbidSort": True,
            "boundedLimit": 1,
            "sql": (
                "SELECT id, attempt_ref FROM public.billium_execution_attempt "
                "WHERE installation_ref = 'inst-bench' AND environment = 'test' "
                "AND provider_id = 'provider-a' AND merchant_account_ref = 'account-a' "
                "AND provider_reference >= 'prov-ref-1' ORDER BY provider_reference LIMIT 1"
            ),
        },
        {
            "id": "execution.binding_by_identity_digest",
            "category": "execution",
            "table": "billium_execution_business_binding",
            "expectedIndexes": ["billium_execution_business_identity_digest_unique"],
            "forbidSort": False,
            "boundedLimit": 1,
            "sql": (
                "SELECT id, operation_ref FROM public.billium_execution_business_binding "
                "WHERE business_identity_digest = 'sha256:bench-identity' LIMIT 1"
            ),
        },
        {
            "id": "execution.recon_item_by_review",
            "category": "execution",
            "table": "billium_execution_recon_item",
            "expectedIndexes": ["billium_execution_recon_item_review_idx"],
            "forbidSort": False,
            "boundedLimit": 1,
            "sql": (
                "SELECT work_ref FROM public.billium_execution_recon_item "
                "WHERE review_ref = 'review-bench' AND evidence_ref = 'evidence-bench' LIMIT 1"
            ),
        },
        {
            "id": "execution.operation_by_root",
            "category": "execution",
            "table": "billium_execution_operation",
            "expectedIndexes": ["billium_execution_operation_root_idx"],
            "forbidSort": False,
            "boundedLimit": 50,
            "sql": (
                "SELECT id, operation_ref, state FROM public.billium_execution_operation "
                "WHERE installation_ref = 'inst-bench' AND seller_ref = 'bench-seller-a' "
                "AND environment = 'test' AND intent_owner_namespace = 'remote-billium' "
                "AND root_operation_ref = 'root-a-1' LIMIT 50"
            ),
        },
        {
            "id": "collection.due_work_batch",
            "category": "collection",
            "table": "billium_collection_work",
            "expectedIndexes": ["billium_collection_work_due_idx"],
            "forbidSort": True,
            "boundedLimit": 50,
            "sql": (
                "SELECT step_id, next_attempt_at FROM public.billium_collection_work "
                "WHERE seller_ref = 'bench-seller-a' AND status = 'due' "
                "AND next_attempt_at IS NOT NULL AND next_attempt_at <= now() "
                "ORDER BY next_attempt_at, step_id LIMIT 50"
            ),
        },
    ]


def collect_plan_nodes(node: dict[str, Any]) -> tuple[list[str], list[str], list[str]]:
    node_types: list[str] = []
    indexes: list[str] = []
    seq_scan_tables: list[str] = []

    def walk(current: dict[str, Any]) -> None:
        node_type = current.get("Node Type")
        if isinstance(node_type, str):
            node_types.append(node_type)
            if node_type == "Seq Scan":
                rel = current.get("Relation Name")
                seq_scan_tables.append(str(rel) if rel else "unknown")
        idx = current.get("Index Name")
        if isinstance(idx, str):
            indexes.append(idx)
        for child in current.get("Plans", []) or []:
            if isinstance(child, dict):
                walk(child)

    walk(node)
    return node_types, indexes, seq_scan_tables


def run_psql(sql: str) -> str:
    host = os.environ.get("DB_HOST", "postgres")
    port = os.environ.get("DB_PORT", "5432")
    database = os.environ.get("DB_DATABASE", "billium_test")
    user = os.environ.get("DB_OWNER_USERNAME") or os.environ.get("DB_USERNAME", "billium")
    password = os.environ.get("DB_OWNER_PASSWORD") or os.environ.get("DB_PASSWORD", "billium")

    env = os.environ.copy()
    env["PGPASSWORD"] = password
    cmd = [
        "psql",
        "-X",
        "-q",
        "-v",
        "ON_ERROR_STOP=1",
        "-P",
        "pager=off",
        "-A",
        "-t",
        "-F",
        "\t",
        "-h",
        host,
        "-p",
        port,
        "-U",
        user,
        "-d",
        database,
    ]
    proc = subprocess.run(cmd, input=sql, text=True, capture_output=True, env=env, check=False)
    if proc.returncode != 0:
        raise RuntimeError(f"psql exited with {proc.returncode}: {proc.stderr.strip()}")
    return proc.stdout


def build_sql_script(rows_per_seller: int, specs: list[dict[str, Any]]) -> str:
    sellers = ["bench-seller-a", "bench-seller-b", "bench-seller-c"]
    lines: list[str] = [
        "BEGIN;",
        "SELECT '__META_VERSION__' || E'\\t' || current_setting('server_version');",
        (
            "SELECT '__META_INDEXES__' || E'\\t' || coalesce(json_agg(indexname ORDER BY indexname)::text, '[]') "
            "FROM pg_indexes WHERE schemaname = 'public' AND indexname IN ("
            + ", ".join(f"'{idx}'" for idx in EXPECTED_INDEXES)
            + ");"
        ),
    ]

    for seller in sellers:
        scope = (
            '{"environment":"test","incarnation":"1",'
            f'"installationRef":"inst-bench","sellerRef":"{seller}"' + "}"
        )
        fin_scope = (
            '{"environment":"test","installationRef":"inst-bench",'
            f'"intentOwnerNamespace":"remote-billium","sellerRef":"{seller}",'
            '"sourceAuthority":"source-a"}'
        )
        lines.append(
            "INSERT INTO public.billium_idempotency_receipt "
            "(environment, seller_ref, command_kind, command_key, digest, outcome, result, completed_at, financial_scope) "
            f"SELECT 'test', '{seller}', 'payment.execute.v1', 'bench-cmd-{seller}-' || n, "
            "'sha256:' || repeat('a', 64), 'accepted', "
            f"jsonb_build_object('class', 'execution.operation', 'body', jsonb_build_object('receiptRef', 'bench-rcpt-{seller}-' || n)), "
            f"now(), '{fin_scope}'::jsonb "
            f"FROM generate_series(1, {rows_per_seller}) n "
            "ON CONFLICT DO NOTHING;"
        )
        lines.append(
            "INSERT INTO public.billium_outbox_event "
            "(event_id, seller_ref, environment, type, schema_version, aggregate_ref, aggregate_revision, "
            "occurred_at, digest, payload, contract_revision, owner_module_id, source_scope) "
            f"SELECT 'bench-evt-{seller}-' || n, '{seller}', 'test', 'obligation.created.v1', 'billium.event.v1', "
            f"'obligation-{seller}-' || n, 1, now(), 'sha256:' || repeat('b', 64), '{{}}'::jsonb, "
            f"'events-2026-10-v1', 'ledger', '{scope}' "
            f"FROM generate_series(1, {rows_per_seller}) n "
            "ON CONFLICT DO NOTHING;"
        )
        lines.append(
            "INSERT INTO public.billium_outbox_delivery "
            "(event_id, destination_ref, status, available_at, delivered_at) "
            f"SELECT 'bench-evt-{seller}-' || n, 'default', "
            "CASE WHEN n <= 5 THEN 'pending' WHEN n <= 15 THEN 'pending' ELSE 'delivered' END, "
            "CASE WHEN n <= 5 THEN now() - interval '5 seconds' WHEN n <= 15 THEN now() + interval '1 hour' ELSE now() - interval '1 minute' END, "
            "CASE WHEN n > 15 THEN now() ELSE NULL END "
            f"FROM generate_series(1, {rows_per_seller}) n "
            "ON CONFLICT DO NOTHING;"
        )

    lines.append("ANALYZE public.billium_idempotency_receipt, public.billium_outbox_event, public.billium_outbox_delivery;")
    lines.append("SET LOCAL enable_seqscan = off;")

    for spec in specs:
        lines.append(f"SELECT '__PLAN_START__' || E'\\t' || '{spec['id']}';")
        lines.append(f"EXPLAIN (FORMAT JSON) {spec['sql']};")
        lines.append("SELECT '__PLAN_END__';")

    lines.append("ROLLBACK;")
    return "\n".join(lines) + "\n"


def parse_output(raw: str) -> tuple[str, list[str], dict[str, list[dict[str, Any]]]]:
    version = "unknown"
    found_indexes: list[str] = []
    plans: dict[str, list[dict[str, Any]]] = {}

    current_id: str | None = None
    buf: list[str] = []

    for line in raw.splitlines():
        if line.startswith("__META_VERSION__\t"):
            version = line.split("\t", 1)[1].strip()
        elif line.startswith("__META_INDEXES__\t"):
            found_indexes = json.loads(line.split("\t", 1)[1].strip())
        elif line.startswith("__PLAN_START__\t"):
            current_id = line.split("\t", 1)[1].strip()
            buf = []
        elif line == "__PLAN_END__" and current_id is not None:
            plans[current_id] = json.loads("\n".join(buf))
            current_id = None
            buf = []
        elif current_id is not None:
            buf.append(line)

    return version, found_indexes, plans


def evaluate(profile: str, rows_per_seller: int) -> dict[str, Any]:
    specs = build_query_specs()
    sql = build_sql_script(rows_per_seller, specs)
    raw = run_psql(sql)
    pg_version, found_indexes, raw_plans = parse_output(raw)

    missing_indexes = [idx for idx in EXPECTED_INDEXES if idx not in found_indexes]
    results: list[dict[str, Any]] = []

    for spec in specs:
        qid = spec["id"]
        plan_doc = raw_plans.get(qid)
        if not plan_doc or not isinstance(plan_doc, list) or "Plan" not in plan_doc[0]:
            results.append(
                {
                    "id": qid,
                    "category": spec["category"],
                    "table": spec["table"],
                    "passed": False,
                    "error": "Missing EXPLAIN JSON plan output",
                }
            )
            continue

        root = plan_doc[0]["Plan"]
        node_types, indexes_used, seq_scan_tables = collect_plan_nodes(root)
        expected = spec["expectedIndexes"]
        expected_matched = any(idx in indexes_used for idx in expected)
        has_seq_scan = len(seq_scan_tables) > 0
        has_sort = "Sort" in node_types
        sort_ok = (not spec["forbidSort"]) or (not has_sort)
        passed = expected_matched and (not has_seq_scan) and sort_ok

        results.append(
            {
                "id": qid,
                "category": spec["category"],
                "table": spec["table"],
                "expectedIndexes": expected,
                "indexesUsed": sorted(set(indexes_used)),
                "nodeTypes": node_types,
                "hasSeqScan": has_seq_scan,
                "seqScanTables": seq_scan_tables,
                "hasSort": has_sort,
                "forbidSort": spec["forbidSort"],
                "boundedLimit": spec["boundedLimit"],
                "totalCost": root.get("Total Cost"),
                "planRows": root.get("Plan Rows"),
                "passed": passed,
            }
        )

    passed_count = sum(1 for item in results if item["passed"])
    failed_count = len(results) - passed_count
    all_passed = (failed_count == 0) and (len(missing_indexes) == 0)

    return {
        "schema": "billium.bench.query_plans.v1",
        "plan": "2026.10.14-№1-BILLIUM-V1",
        "task": "P10.4",
        "profile": profile,
        "mode": "pragmatic-light",
        "decisions": ["D71", "D72"],
        "recordedAt": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "postgresVersion": pg_version,
        "dataset": {
            "sellers": 3,
            "rowsPerSeller": rows_per_seller,
            "seededTables": [
                "billium_idempotency_receipt",
                "billium_outbox_event",
                "billium_outbox_delivery",
            ],
            "enableSeqscan": "off (transaction-local, rolled back)",
            "persistentMutation": False,
        },
        "indexCatalog": {
            "expectedCount": len(EXPECTED_INDEXES),
            "presentCount": len(found_indexes),
            "missing": missing_indexes,
        },
        "queries": results,
        "summary": {
            "totalQueries": len(results),
            "passedQueries": passed_count,
            "failedQueries": failed_count,
            "missingIndexes": len(missing_indexes),
            "allPassed": all_passed,
        },
    }


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(
        description="Lightweight PostgreSQL EXPLAIN query-plan check for Billium hot paths."
    )
    parser.add_argument(
        "--profile",
        choices=["acceptance", "lock-wait"],
        default="acceptance",
        help="Verification profile to execute (default: acceptance).",
    )
    parser.add_argument(
        "--output",
        default="-",
        help="Output path for JSON report, or '-' for stdout (default: '-').",
    )
    parser.add_argument(
        "--rows-per-seller",
        type=int,
        default=30,
        help="Compact deterministic rows seeded per seller inside the rolled-back transaction (default: 30).",
    )
    args = parser.parse_args(argv)

    if args.rows_per_seller < 1 or args.rows_per_seller > 1000:
        print("rows-per-seller must be between 1 and 1000", file=sys.stderr)
        return 2

    try:
        report = evaluate(args.profile, args.rows_per_seller)
    except Exception as exc:
        print(f"Query-plan check failed: {exc}", file=sys.stderr)
        return 1

    payload = json.dumps(report, indent=2, ensure_ascii=False) + "\n"
    if args.output == "-":
        sys.stdout.write(payload)
    else:
        with open(args.output, "w", encoding="utf-8") as fh:
            fh.write(payload)

    return 0 if report["summary"]["allPassed"] else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
