#!/usr/bin/env python3
"""Check the server API map against the revision, the host routes and the shape vectors.

The normative routes and the HTTP status function live here. By default the script writes
spec/server/openapi.v1.json, the server's PHP wire metadata, the generated sections of
docs/contracts/server-api-v1.md and the shape vectors. --check compares and never writes.
--routes checks the Laravel runtime route registry against the OpenAPI manifest.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts/spec"))
sys.path.insert(0, str(ROOT / "conformance/oracles/jcs"))

from export_common import ENVELOPES  # noqa: E402
import lint  # noqa: E402
import oracle  # noqa: E402

REV = ROOT / "spec/revisions/2026-10"
OPENAPI_PATH = ROOT / "spec/server/openapi.v1.json"
DOC_PATH = ROOT / "docs/contracts/server-api-v1.md"
HTTP_ROUTES = ROOT / "packages/server/routes/http.php"
PHP_CONTRACT = ROOT / "packages/server/src/Internal/Http/HttpContract.php"
SHAPE_DIR = ROOT / "fixtures/integration/http/vectors/shape"
VECTOR_DIR = ROOT / "fixtures/vectors/http/shape"
REJECTED = ROOT / "fixtures/integration/http/schema/rejected-document-route.json"
DEFS = "urn:billium:spec:2026-10:common/defs.json"

FEATURES = ("charge", "refund", "find", "receipt", "document", "execution")
EFFECTS = ("sale", "authorize", "capture", "voidAuthorization", "cancel")
FORBIDDEN_CAPABILITY_NAMES = {
    "credential", "credentials", "secret", "secrets", "password", "token", "ticket", "tickets",
    "permission", "permissions", "sellers", "sellerRef", "privateKey", "apiKey", "secretLocator",
}
QUERY_RESULTS = {
    "paymentStatus": "ledger.payment_status.v1",
    "dueObligations": "ledger.due_obligations.v1",
    "obligation": "ledger.obligation.v1",
    "invoice": "ledger.invoice.v1",
    "allocationByOperation": "ledger.allocations.v1",
    "refundAuthorization": "ledger.refund_authorization.v1",
}
D57_FIELDS = (
    "applied", "observed", "financialHold", "exposure", "spendable", "projectionStatus",
    "pendingAllocationReason", "reconciliation",
)
ROUTE_RE = re.compile(r"\$router->(get|post)\('([^']+)'.*?->name\('([^']+)'\)", re.S)
SECTION_RE = {
    "problem": re.compile(r"<!-- billium-problem-http:start -->.*?<!-- billium-problem-http:end -->", re.S),
    "routes": re.compile(r"<!-- billium-routes:start -->.*?<!-- billium-routes:end -->", re.S),
    "installed": re.compile(r"<!-- billium-installed:start -->.*?<!-- billium-installed:end -->", re.S),
}

# method, path, auth, request schema or None, result schema or None, success statuses, extras
ROUTES: tuple[dict[str, object], ...] = (
    {"method": "GET", "path": "/billium/live", "auth": "health.live", "request": None, "result": None, "statuses": [200]},
    {"method": "GET", "path": "/billium/ready", "auth": "health.ready", "request": None, "result": None, "statuses": [200, 503]},
    {"method": "GET", "path": "/billium/v1/capabilities", "auth": "capabilities.read", "request": None, "result": "billium.capabilities.v1", "statuses": [200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/payments/execute", "auth": "payment.execute", "request": "payment.execute.v1", "result": "payment.admission.v1", "statuses": [202, 200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/payments/refund", "auth": "payment.refund", "request": "payment.refund.v1", "result": "payment.admission.v1", "statuses": [202, 200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/payments/authorize", "auth": "payment.authorize", "request": "payment.authorize.v1", "result": "payment.admission.v1", "statuses": [202, 200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/payments/capture", "auth": "payment.capture", "request": "payment.capture.v1", "result": "payment.admission.v1", "statuses": [202, 200], "outcome": "payment.effect.outcome.v1"},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/payments/void-authorization", "auth": "payment.void_authorization", "request": "payment.void_authorization.v1", "result": "payment.admission.v1", "statuses": [202, 200], "outcome": "payment.effect.outcome.v1"},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/operations/{operationRef}/cancel", "auth": "payment.cancel", "request": "payment.cancel.v1", "result": "payment.admission.v1", "statuses": [202, 200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/operations/find", "auth": "payment.find", "request": "payment.find.v1", "result": "payment.find.v1", "statuses": [200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/obligations/prepare", "auth": "obligation.prepare", "request": "obligation.prepare.v1", "result": "billium.command_receipt.v1", "statuses": [200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/occurrences/ensure", "auth": "occurrence.ensure", "request": "occurrence.ensure.v1", "result": "billium.command_receipt.v1", "statuses": [200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/collection/authorize", "auth": "collection.authorize", "request": "collection.authorize.v1", "result": "billium.command_receipt.v1", "statuses": [200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/observations", "auth": "payment.observe", "request": "payment.observation.v1", "result": "ledger.allocation_receipt.v1", "statuses": [200, 202], "custody": "ledger.observation_custody.v1", "http21": True},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/usage/facts", "auth": "usage.accept", "request": "usage.fact.v1", "result": "usage.receipt.v1", "statuses": [200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/allocations/reallocate", "auth": "allocation.reallocate", "request": "allocation.reallocate.v1", "result": "ledger.allocation_receipt.v1", "statuses": [200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/queries", "auth": "ledger.query", "request": "billium.query.v1", "result": None, "statuses": [200], "results": QUERY_RESULTS},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/receipts/find", "auth": "receipt.find", "request": "receipt.find.v1", "result": "receipt.find.v1", "statuses": [200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/feed/open", "auth": "feed.open", "request": "billium.feed.open.v1", "result": "billium.feed.selection.v1", "statuses": [200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/feed/read", "auth": "feed.read", "request": "billium.feed.read.v1", "result": "billium.feed.page.v1", "statuses": [200]},
    {"method": "POST", "path": "/billium/v1/sellers/{sellerRef}/feed/bootstrap", "auth": "feed.bootstrap", "request": "billium.feed.bootstrap_request.v1", "result": "billium.feed.bootstrap.v1", "statuses": [200]},
)


def problem_http_status(entry: dict[str, object]) -> int:
    """Closed HTTP status of one registry code. Receipt policy stays the registry's."""
    code = str(entry["code"])
    category = str(entry["category"])
    if code == "billium.auth.unauthenticated":
        return 401
    if category == "auth":
        return 403
    if category in {"validation", "schema", "money"}:
        return 400
    if code == "billium.key.expired":
        return 410
    if code in {"billium.recovery.required", "billium.installation.quarantined"}:
        return 423
    if code in {"billium.idempotency.in_flight", "billium.lookup.unavailable", "billium.transport.unavailable"}:
        return 503
    return 409


def digest_of(body: dict[str, object]) -> str:
    clone = dict(body)
    clone.pop("digest", None)
    canonical = oracle.canonicalize(json.dumps(clone, ensure_ascii=False))
    return "sha256:" + hashlib.sha256(canonical).hexdigest()


def load_json(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


def schemas() -> dict[str, dict[str, object]]:
    found: dict[str, dict[str, object]] = {}
    for path in (REV / "schema").rglob("*.json"):
        document = load_json(path)
        if isinstance(document, dict):
            found[path.relative_to(REV / "schema").as_posix()] = document
    return found


def schema_by_name(documents: dict[str, dict[str, object]]) -> dict[str, tuple[str, dict[str, object]]]:
    found: dict[str, tuple[str, dict[str, object]]] = {}
    for rel, document in documents.items():
        props = document.get("properties")
        if isinstance(props, dict) and isinstance(props.get("schema"), dict) and "const" in props["schema"]:
            found[str(props["schema"]["const"])] = (rel, document)
        defs = document.get("$defs")
        if isinstance(defs, dict):
            for name, node in defs.items():
                if isinstance(node, dict) and isinstance(node.get("properties"), dict):
                    schema = node["properties"].get("schema")
                    if isinstance(schema, dict) and "const" in schema:
                        found.setdefault(str(schema["const"]), (rel, document))
    return found


def node_for(documents: dict[str, dict[str, object]], name: str, pointer: str) -> dict[str, object] | None:
    for document in documents.values():
        if pointer:
            node = document.get("$defs", {}).get(pointer.rsplit("/", 1)[-1])  # type: ignore[union-attr]
        else:
            node = document
        if not isinstance(node, dict):
            continue
        props = node.get("properties")
        if isinstance(props, dict) and isinstance(props.get("schema"), dict) and props["schema"].get("const") == name:
            return node
    return None


def has_command_key(documents: dict[str, dict[str, object]], name: str | None) -> bool:
    if name is None:
        return False
    for pointer in ("", "/$defs/request"):
        node = node_for(documents, name, pointer)
        if node is not None and "commandKey" in node.get("properties", {}):  # type: ignore[operator]
            return True
    return False


def installed_routes() -> list[dict[str, str]]:
    text = HTTP_ROUTES.read_text(encoding="utf-8")
    return [
        {"method": method.upper(), "path": path, "name": name}
        for method, path, name in ROUTE_RE.findall(text)
    ]


def status_map(codes: list[dict[str, object]]) -> dict[str, int]:
    return {str(entry["code"]): problem_http_status(entry) for entry in codes}


def check_installed_routes(installed: list[dict[str, str]], errors: list[str]) -> None:
    expected = {(route["method"], route["path"]) for route in ROUTES}
    actual = {(route["method"], route["path"]) for route in installed}
    if actual != expected:
        errors.append(f"installed routes differ from the declared HTTP map: {sorted(actual ^ expected)}")
    if len(actual) != len(installed) or len({route["name"] for route in installed}) != len(installed):
        errors.append("installed routes contain a duplicate method/path or name")


def render_php_contract(codes: list[dict[str, object]], installed: list[dict[str, str]]) -> str:
    spec_hash = "sha256:" + hashlib.sha256((REV / "revision.lock.json").read_bytes()).hexdigest()
    names = {(route["method"], route["path"]): route["name"] for route in installed}
    lines = [
        "<?php", "", "declare(strict_types=1);", "", "namespace Billium\\Server\\Internal\\Http;",
        "", "use Packman\\Attributes\\Internal;", "",
        "/** Generated by scripts/spec/check-server-api.py. */", "#[Internal]", "final class HttpContract",
        "{", f"    public const string SPEC_HASH = '{spec_hash}';", "",
        "    public const array PROBLEM_HTTP = [",
    ]
    lines.extend(f"        '{code}' => {status}," for code, status in status_map(codes).items())
    lines.extend(["    ];", "", "    public const array QUERY_RESULTS = ["])
    lines.extend(f"        '{query}' => '{schema}'," for query, schema in QUERY_RESULTS.items())
    lines.extend(["    ];", "", "    public const array ROUTES = ["])
    for route in ROUTES:
        name = names.get((route["method"], route["path"]))
        if name is None:
            continue
        request = "null" if route["request"] is None else f"'{route['request']}'"
        results = [route[key] for key in ("result", "custody", "outcome") if route.get(key)]
        results.extend(route.get("results", {}).values())
        responses = ", ".join(f"'{schema}'" for schema in results)
        lines.append(f"        '{name}' => ['request' => {request}, 'responses' => [{responses}]],")
    lines.extend(["    ];", "}", ""])
    return "\n".join(lines)


def render_problem_table(codes: list[dict[str, object]]) -> str:
    lines = [
        "| Код | HTTP | Категория | Повторяемо | Тот же ключ | Receipt |",
        "|:--|--:|:--|:--|:--|:--|",
    ]
    for entry in codes:
        lines.append(
            "| `{code}` | {status} | {category} | {retryable} | {same} | {receipt} |".format(
                code=entry["code"],
                status=problem_http_status(entry),
                category=entry["category"],
                retryable="да" if entry["retryable"] else "нет",
                same="да" if entry["sameKey"] else "нет",
                receipt=entry["receipt"],
            )
        )
    return "\n".join(lines)


def route_note(route: dict[str, object]) -> str:
    notes: list[str] = []
    if route.get("http21") is True:
        notes.append("HTTP-21: grant только из principal; impersonation → 403 billium.auth.denied, receipt none")
    if "custody" in route:
        notes.append(f"202 → `{route['custody']}`")
    if "outcome" in route:
        notes.append(f"дочерний исход `{route['outcome']}`")
    if "results" in route:
        pairs = ", ".join(f"{key}→`{value}`" for key, value in route["results"].items())  # type: ignore[union-attr]
        notes.append(pairs)
        notes.append("readRender без HTTP-результата")
    return "; ".join(notes)


def render_route_table() -> str:
    lines = [
        "| Метод | Путь | Действие | Запрос | Результат | Успех | Примечание |",
        "|:--|:--|:--|:--|:--|:--|:--|",
    ]
    for route in ROUTES:
        lines.append(
            "| {method} | `{path}` | `{auth}` | {request} | {result} | {statuses} | {note} |".format(
                method=route["method"],
                path=route["path"],
                auth=route["auth"],
                request=f"`{route['request']}`" if route["request"] else "—",
                result=f"`{route['result']}`" if route["result"] else "—",
                statuses=", ".join(str(code) for code in route["statuses"]),  # type: ignore[union-attr]
                note=route_note(route) or "—",
            )
        )
    return "\n".join(lines)


def render_installed_table(routes: list[dict[str, str]]) -> str:
    lines = [
        "| Метод | Путь host | Route name |",
        "|:--|:--|:--|",
    ]
    for route in routes:
        lines.append(f"| {route['method']} | `{route['path']}` | `{route['name']}` |")
    return "\n".join(lines)


def section(kind: str, body: str) -> str:
    return f"<!-- billium-{kind}:start -->\n{body}\n<!-- billium-{kind}:end -->"


def render_doc(codes: list[dict[str, object]], installed: list[dict[str, str]], current: str) -> str:
    replacements = {
        "problem": section("problem-http", render_problem_table(codes)),
        "routes": section("routes", render_route_table()),
        "installed": section("installed", render_installed_table(installed)),
    }
    text = current
    for kind, pattern in SECTION_RE.items():
        if not pattern.search(text):
            raise RuntimeError(f"docs/contracts/server-api-v1.md is missing the {kind} markers")
        text = pattern.sub(replacements[kind], text, count=1)
    return text


def operation(route: dict[str, object], documents: dict[str, dict[str, object]]) -> dict[str, object]:
    op: dict[str, object] = {
        "operationId": route["auth"],
        "x-billium-auth-action": route["auth"],
        "x-billium-request-schema": route["request"],
        "x-billium-result-schema": route["result"],
        "x-billium-statuses": route["statuses"],
        "x-billium-idempotency-header": has_command_key(documents, route["request"] if isinstance(route["request"], str) else None),
        "responses": {str(code): {"description": "success"} for code in route["statuses"]},  # type: ignore[union-attr]
    }
    if "{sellerRef}" in str(route["path"]):
        op["parameters"] = [{"$ref": "#/components/parameters/sellerRef"}]
    if "outcome" in route:
        op["x-billium-outcome-schema"] = route["outcome"]
    if "custody" in route:
        op["x-billium-custody-schema"] = route["custody"]
    if "results" in route:
        op["x-billium-result-schemas"] = route["results"]
    if route.get("http21") is True:
        op["x-http-21"] = {
            "id": "HTTP-21",
            "authority": "principal-derived SourceAuthorityGrant",
            "bodyDerivedAuthority": False,
            "impersonation": {
                "status": 403,
                "code": "billium.auth.denied",
                "receipt": "none",
                "inboxRows": 0,
                "existenceLeak": False,
            },
        }
    return op


def render_openapi(codes: list[dict[str, object]], installed: list[dict[str, str]], documents: dict[str, dict[str, object]]) -> str:
    paths: dict[str, dict[str, object]] = {}
    for route in ROUTES:
        paths.setdefault(str(route["path"]), {})[str(route["method"]).lower()] = operation(route, documents)
    document = {
        "openapi": "3.1.0",
        "info": {
            "title": "Billium server API",
            "version": "1",
            "description": "Normative HTTP map for revision 2026-10. Generated by scripts/spec/check-server-api.py.",
        },
        "x-billium-problem-http": status_map(codes),
        "x-billium-installed-routes": installed,
        "paths": paths,
        "components": {
            "parameters": {
                "sellerRef": {
                    "name": "sellerRef",
                    "in": "path",
                    "required": True,
                    "schema": {"type": "string", "minLength": 1},
                    "description": "Selector. Compared with the authenticated context and the body.",
                }
            }
        },
    }
    return json.dumps(document, ensure_ascii=False, indent=2) + "\n"


def minimal(name: str, **fields: object) -> dict[str, object]:
    body: dict[str, object] = {"schema": name, "installationRef": "inst-1", "environment": "test", "sellerRef": "seller-1"}
    body.update(fields)
    return body


def scope() -> dict[str, str]:
    return {
        "installationRef": "inst-1",
        "channel": "payment.outcome",
        "sourceAuthority": "embedded-commerz",
        "sellerRef": "seller-1",
        "environment": "test",
        "lineage": "lineage-1",
    }


def capabilities_body() -> dict[str, object]:
    body: dict[str, object] = {
        "schema": "billium.capabilities.v1",
        "installationRef": "inst-1",
        "environment": "test",
        "specRevision": "2026-10",
        "specHash": "sha256:" + "ab" * 32,
        "serverApiVersion": "1",
        "acceptedCommandSchemas": ["payment.execute.v1", "billium.feed.open.v1"],
        "producedResponseSchemas": ["payment.admission.v1", "billium.feed.selection.v1"],
        "eventSchemas": ["billium.event.v1"],
        "enabledModules": [{"module": "ledger", "apiVersion": "1"}],
        "moduleApiVersions": {"ledger": "1"},
        "features": {"charge": True, "refund": True, "find": True, "receipt": True, "document": False, "execution": False},
        "effects": {"sale": True, "authorize": False, "capture": False, "voidAuthorization": False, "cancel": True},
        "limits": {"maxBodyBytes": 1048576, "maxDepth": 32},
        "lookupCompletenessPolicyRef": "policy/lookup-complete",
        "catalogs": [{
            "eventContractRevision": "events-2026-10-v1",
            "envelopeSchema": "billium.event.v1",
            "eventTypes": ["payment.outcome.v1"],
        }],
        "selectionMode": "explicit",
        "trace": "span-1",
    }
    body["digest"] = digest_of(body)
    return body


def receipt_result_body() -> dict[str, object]:
    body = minimal(
        "receipt.find.v1",
        receiptRef="allocation:a1",
        status="found",
        receiptSchema="ledger.allocation_receipt.v1",
        body={"kind": "assign"},
        trace="span-1",
    )
    body["digest"] = digest_of(body)
    return body


def closed_unknown() -> list[tuple[str, str, dict[str, object]]]:
    digest = "sha256:" + "0" * 64
    key = "9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d"
    return [
        ("receipt-find-unknown-field", "receipt.find.v1", minimal("receipt.find.v1", receiptRef="allocation:a1", future=True)),
        ("feed-open-unknown-field", "billium.feed.open.v1", minimal(
            "billium.feed.open.v1", commandKey=key, scope=scope(), eventContractRevision="events-2026-10-v1",
            envelopeSchemas=["billium.event.v1"], eventTypes=["payment.outcome.v1"], future=True,
        )),
        ("feed-read-unknown-field", "billium.feed.read.v1", minimal(
            "billium.feed.read.v1", subscriptionRef="sub-1", selectionDigest=digest, cursor="cursor-1", future=True,
        )),
        ("feed-bootstrap-unknown-field", "billium.feed.bootstrap_request.v1", minimal(
            "billium.feed.bootstrap_request.v1", commandKey=key, subscriptionRef="sub-1", selectionDigest=digest, future=True,
        )),
    ]


def conformance_vector(vector_id: str, json_text: str, expect: dict[str, object], refs: list[str]) -> dict[str, object]:
    return {
        "id": vector_id,
        "spec_refs": refs,
        "profiles": ["php", "dart"],
        "given": {},
        "script": [{"op": "envelope.decode", "args": {"json_text": json_text}}],
        "expect": [expect],
    }


def shape_files() -> dict[Path, str]:
    files: dict[Path, str] = {}
    refs = ["BC-ENV-04", "BC-ENV-06", "BC-ID-12", "D32", "REQ-CON-20"]
    for stem, _name, body in closed_unknown():
        text = json.dumps(body, ensure_ascii=False, separators=(",", ":"))
        shape = {"id": f"http/shape/{stem}", "kind": "reject-unknown", "schema": body["schema"], "body": body}
        files[SHAPE_DIR / f"{stem}.json"] = json.dumps(shape, ensure_ascii=False, indent=2) + "\n"
        vector = conformance_vector(
            f"http/shape/{stem}", text, {"error": {"code": "billium.validation.invalid"}}, ["BC-ENV-04", "REQ-CON-20"],
        )
        files[VECTOR_DIR / f"{stem}.json"] = json.dumps(vector, ensure_ascii=False, indent=2) + "\n"
    preserved = {
        "capabilities-unknown-digest": capabilities_body(),
        "receipt-find-unknown-digest": receipt_result_body(),
    }
    for stem, body in preserved.items():
        text = json.dumps(body, ensure_ascii=False, separators=(",", ":"))
        shape = {"id": f"http/shape/{stem}", "kind": "preserve-unknown", "schema": body["schema"], "body": body}
        files[SHAPE_DIR / f"{stem}.json"] = json.dumps(shape, ensure_ascii=False, indent=2) + "\n"
        vector = conformance_vector(
            f"http/shape/{stem}",
            text,
            {"ok": {"schema": body["schema"], "digest": body["digest"]}},
            refs,
        )
        files[VECTOR_DIR / f"{stem}.json"] = json.dumps(vector, ensure_ascii=False, indent=2) + "\n"
    unknown = conformance_vector(
        "http/shape/receipt-find-unknown-major",
        '{"schema":"receipt.find.v2"}',
        {"error": {"code": "billium.schema.unsupported"}},
        ["BC-ENV-04", "REQ-CON-20"],
    )
    files[VECTOR_DIR / "receipt-find-unknown-major.json"] = json.dumps(unknown, ensure_ascii=False, indent=2) + "\n"
    files[SHAPE_DIR / "receipt-find-unknown-major.json"] = json.dumps(
        {"id": "http/shape/receipt-find-unknown-major", "kind": "unknown-major", "schema": "receipt.find.v2"},
        ensure_ascii=False,
        indent=2,
    ) + "\n"
    return files


def placeholder_rejection(route: dict[str, object]) -> str | None:
    path = str(route.get("path", ""))
    if "document" in path.lower():
        return "document endpoints are not part of this revision"
    request = route.get("requestSchema") or route.get("request")
    if isinstance(request, str) and request.startswith("document."):
        return "document command schema is not implemented"
    return None


def property_names(node: object, found: set[str]) -> None:
    if isinstance(node, dict):
        props = node.get("properties")
        if isinstance(props, dict):
            found.update(str(key) for key in props)
        for value in node.values():
            property_names(value, found)
    elif isinstance(node, list):
        for value in node:
            property_names(value, found)


def check_status_rules(codes: list[dict[str, object]], errors: list[str]) -> None:
    for entry in codes:
        status = problem_http_status(entry)
        code = str(entry["code"])
        if status == 429:
            errors.append(f"{code}: 429 is not emitted")
        if entry["receipt"] == "stored" and status != 409:
            errors.append(f"{code}: a stored receipt is a business conflict")
        if status in {400, 401, 403, 410, 423, 503} and entry["receipt"] != "none":
            errors.append(f"{code}: HTTP {status} keeps no receipt")
        if status == 503 and entry["retryable"] is not True:
            errors.append(f"{code}: HTTP 503 is retryable")
        if status == 401 and code != "billium.auth.unauthenticated":
            errors.append(f"{code}: only missing credentials are 401")


def check_shapes(documents: dict[str, dict[str, object]], enrolled: set[str], errors: list[str]) -> None:
    from jsonschema import Draft202012Validator
    from referencing import Registry
    from referencing.jsonschema import DRAFT202012

    resources = []
    for rel, document in documents.items():
        resources.append((f"urn:billium:spec:2026-10:{rel}", DRAFT202012.create_resource(document)))
    registry = Registry().with_resources(resources)
    expected = shape_files()
    for path, text in expected.items():
        if not path.is_file() or path.read_text(encoding="utf-8") != text:
            errors.append(f"{path.relative_to(ROOT)} is stale; run scripts/spec/check-server-api.py")
            continue
        shape_path = SHAPE_DIR / path.name if path.parent == VECTOR_DIR else path
        if path.parent != SHAPE_DIR:
            continue
        shape = json.loads(text)
        kind = shape["kind"]
        if kind == "unknown-major":
            if shape["schema"] in enrolled:
                errors.append(f"{shape['schema']} is enrolled; an unknown major must be refused")
            continue
        if shape["schema"] == "receipt.find.v1":
            pointer = "/$defs/request" if kind == "reject-unknown" else "/$defs/response"
        else:
            pointer = ""
        matches = [rel for rel, document in documents.items() if node_for({rel: document}, shape["schema"], pointer)]
        if len(matches) != 1:
            errors.append(f"{shape['id']}: schema node {shape['schema']} {pointer} not found")
            continue
        node_rel = matches[0]
        ref = f"urn:billium:spec:2026-10:{node_rel}"
        if pointer:
            ref += "#" + pointer
        validator = Draft202012Validator({"$ref": ref}, registry=registry)
        failures = list(validator.iter_errors(shape["body"]))
        if kind == "reject-unknown":
            if not failures or any(failure.validator != "additionalProperties" for failure in failures):
                errors.append(f"{shape['id']}: unknown field is not rejected by additionalProperties alone")
        elif kind == "preserve-unknown":
            if failures:
                errors.append(f"{shape['id']}: open response rejected an optional field: {failures[0].message}")
            elif "trace" not in shape["body"] or shape["body"].get("digest") != digest_of(shape["body"]):
                errors.append(f"{shape['id']}: digest does not cover the unknown field")
        else:
            errors.append(f"{shape['id']}: unknown kind {kind}")
    if not REJECTED.is_file():
        errors.append("negative document route fixture is missing")
    else:
        reason = placeholder_rejection(json.loads(REJECTED.read_text(encoding="utf-8")))
        if reason is None:
            errors.append("negative document route was accepted")


def check_capabilities(documents: dict[str, dict[str, object]], errors: list[str]) -> None:
    rel, document = schema_by_name(documents)["billium.capabilities.v1"]
    if document.get("additionalProperties") is False:
        errors.append(f"{rel}: capabilities stay open for optional metadata")
    names: set[str] = set()
    property_names(document, names)
    leaked = sorted(names & FORBIDDEN_CAPABILITY_NAMES)
    if leaked:
        errors.append(f"capabilities declare forbidden names: {leaked}")
    props = document["properties"]
    features = props["features"]["properties"]
    effects = props["effects"]["properties"]
    if tuple(features) != FEATURES or tuple(effects) != EFFECTS:
        errors.append("capabilities feature or effect names differ from the contract")
    if props["selectionMode"].get("const") != "explicit":
        errors.append("capabilities selectionMode is not explicit")
    if props["serverApiVersion"].get("const") != "1":
        errors.append("serverApiVersion is not 1")


def check_routes(documents: dict[str, dict[str, object]], enrolled: set[str], errors: list[str]) -> None:
    named = schema_by_name(documents)
    for route in ROUTES:
        path = str(route["path"])
        if placeholder_rejection({"path": path, "request": route["request"]}) is not None:
            errors.append(f"{path}: placeholder route")
        for key in ("request", "result", "outcome", "custody"):
            schema = route.get(key)
            if isinstance(schema, str) and schema not in enrolled:
                errors.append(f"{path}: {schema} is not an enrolled envelope")
        results = route.get("results")
        if isinstance(results, dict):
            if set(results) != set(QUERY_RESULTS):
                errors.append("query result map drifted")
            if "readRender" in results:
                errors.append("readRender must not grow an HTTP result")
        if route.get("http21") is True and route.get("custody") != "ledger.observation_custody.v1":
            errors.append("HTTP-21 observation route lost its custody result")
        if 429 in route["statuses"]:  # type: ignore[operator]
            errors.append(f"{path}: 429 is not implemented")
    execute = node_for(documents, "payment.execute.v1", "")
    cancel = node_for(documents, "payment.cancel.v1", "")
    if execute is None or execute.get("additionalProperties") is not False:
        errors.append("payment.execute.v1 is no longer a closed sale command")
    if cancel is None or cancel["properties"]["schema"].get("const") != "payment.cancel.v1":  # type: ignore[index]
        errors.append("payment.cancel.v1 was renamed")
    payment = named["ledger.payment_status.v1"][1]["properties"]["result"]["properties"]  # type: ignore[index]
    missing = [field for field in D57_FIELDS if field not in payment]
    if missing:
        errors.append(f"payment status lost D57 fields: {missing}")
    for name, pointer in sorted(lint.OPEN):
        if name in {"billium.capabilities.v1", "receipt.find.v1", "ledger.allocation_receipt.v1", "ledger.observation_custody.v1", "billium.command_receipt.v1"}:
            node = node_for(documents, name, pointer)
            if node is None or node.get("additionalProperties") is False:
                errors.append(f"{name} response is not open")
    defs = documents["common/defs.json"]["$defs"]["queryKind"]["enum"]  # type: ignore[index]
    if set(defs) - set(QUERY_RESULTS) != {"readRender"}:
        errors.append("query kinds and HTTP result schemas diverged")


def check_openapi(openapi: object, codes: list[dict[str, object]], installed: list[dict[str, str]], documents: dict[str, dict[str, object]], errors: list[str]) -> None:
    if not isinstance(openapi, dict):
        errors.append("openapi is not an object")
        return
    if openapi.get("x-billium-problem-http") != status_map(codes):
        errors.append("openapi problem status map drifted from the registry")
    if openapi.get("x-billium-installed-routes") != installed:
        errors.append("openapi installed routes drifted from packages/server/routes/http.php")
    paths = openapi.get("paths")
    if not isinstance(paths, dict):
        errors.append("openapi has no paths")
        return
    expected = {str(route["path"]): str(route["method"]).lower() for route in ROUTES}
    if set(paths) != set(expected):
        errors.append(f"openapi paths {sorted(set(paths) ^ set(expected))} differ from the route table")
    for route in ROUTES:
        op = paths.get(str(route["path"]), {}).get(str(route["method"]).lower())  # type: ignore[union-attr]
        if not isinstance(op, dict):
            errors.append(f"missing operation {route['method']} {route['path']}")
            continue
        for key, ext in (
            ("auth", "x-billium-auth-action"),
            ("request", "x-billium-request-schema"),
            ("result", "x-billium-result-schema"),
            ("statuses", "x-billium-statuses"),
        ):
            if op.get(ext) != route[key]:
                errors.append(f"{route['path']}: {ext} differs")
        if op.get("x-billium-idempotency-header") != has_command_key(documents, route["request"] if isinstance(route["request"], str) else None):
            errors.append(f"{route['path']}: idempotency header flag differs")
        if route.get("http21") is True and op.get("x-http-21", {}).get("impersonation", {}).get("code") != "billium.auth.denied":
            errors.append("HTTP-21 metadata is missing on observations")
        if "document" in str(route["path"]):
            errors.append("document path published")


def collect() -> list[str]:
    errors: list[str] = []
    codes = load_json(REV / "problem-codes.json")
    if not isinstance(codes, list):
        return ["problem-codes.json is not a list"]
    documents = schemas()
    enrolled = {schema for _class, schema, _source, _variant in ENVELOPES}
    installed = installed_routes()
    check_status_rules(codes, errors)  # type: ignore[arg-type]
    check_installed_routes(installed, errors)
    check_capabilities(documents, errors)
    check_routes(documents, enrolled, errors)
    if not OPENAPI_PATH.is_file():
        errors.append("spec/server/openapi.v1.json is missing")
    else:
        check_openapi(load_json(OPENAPI_PATH), codes, installed, documents, errors)  # type: ignore[arg-type]
    current = DOC_PATH.read_text(encoding="utf-8") if DOC_PATH.is_file() else ""
    try:
        rendered = render_doc(codes, installed, current)  # type: ignore[arg-type]
    except RuntimeError as error:
        errors.append(str(error))
    else:
        if rendered != current:
            errors.append("docs/contracts/server-api-v1.md route sections are stale")
    if OPENAPI_PATH.is_file() and OPENAPI_PATH.read_text(encoding="utf-8") != render_openapi(codes, installed, documents):  # type: ignore[arg-type]
        errors.append("spec/server/openapi.v1.json is stale")
    check_shapes(documents, enrolled, errors)
    if not PHP_CONTRACT.is_file() or PHP_CONTRACT.read_text(encoding="utf-8") != render_php_contract(codes, installed):  # type: ignore[arg-type]
        errors.append("generated PHP HTTP contract drifted")
    return errors


def write() -> None:
    codes = load_json(REV / "problem-codes.json")
    documents = schemas()
    installed = installed_routes()
    OPENAPI_PATH.parent.mkdir(parents=True, exist_ok=True)
    OPENAPI_PATH.write_text(render_openapi(codes, installed, documents), encoding="utf-8")  # type: ignore[arg-type]
    PHP_CONTRACT.write_text(render_php_contract(codes, installed), encoding="utf-8")  # type: ignore[arg-type]
    current = DOC_PATH.read_text(encoding="utf-8")
    DOC_PATH.write_text(render_doc(codes, installed, current), encoding="utf-8")  # type: ignore[arg-type]
    for path, text in shape_files().items():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
    print(f"check-server-api: wrote {OPENAPI_PATH.relative_to(ROOT)}")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--check", action="store_true", help="compare only, never write")
    mode.add_argument("--routes", action="store_true", help="compare the Laravel runtime registry to OpenAPI")
    args = parser.parse_args(argv)
    if args.routes:
        return subprocess.run([
            "php", "vendor/bin/pest", "packages/server/tests/Feature/QueryEndpointsTest.php",
            "--filter", "test_runtime_routes_match_the_http_manifest",
        ], cwd=ROOT, check=False).returncode
    if not args.check:
        write()
        return 0
    errors = collect()
    for error in errors:
        print(f"check-server-api: {error}", file=sys.stderr)
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
