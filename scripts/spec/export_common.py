"""Shared model of the language exporters (export-php.py, export-dart.py).

Reads one spec revision and produces the language-neutral data both exporters render:
the currency table, the problem-code registry, the schema bundle the codecs validate against
and the envelope descriptors behind the generated envelope classes.
"""
from __future__ import annotations

import json
import hashlib
import re
import sys
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SUPPORTED_REVISIONS = ("2026-10", "2026-12")
CURRENT_REVISION = "2026-12"
DEFAULT_REVISION = ROOT / "spec/revisions" / CURRENT_REVISION

ANNOTATIONS = ("$schema", "title", "description", "$comment")
SCHEMA_BASE = "urn:billium:spec:{revision}:{path}"
MONEY_WIRE_PATH = "common/money-wire.json"

# class name, schema name, schema file, variant of a oneOf schema (def name) or None
ENVELOPES = (
    ("ExecutePayment", "payment.execute.v1", "payment/execute.v1.json", None),
    ("AuthorizePayment", "payment.authorize.v1", "payment/authorize.v1.json", None),
    ("CapturePayment", "payment.capture.v1", "payment/capture.v1.json", None),
    ("VoidAuthorization", "payment.void_authorization.v1", "payment/void-authorization.v1.json", None),
    ("ExecutionStatus", "payment.execution_status.v1", "query/execution-status.v1.json", None),
    ("RequestRefund", "payment.refund.v1", "payment/refund.v1.json", None),
    ("CancelOperation", "payment.cancel.v1", "payment/cancel.v1.json", None),
    ("FindOperation", "payment.find.v1", "payment/find.v1.json", "request"),
    ("FindResult", "payment.find.v1", "payment/find.v1.json", "response"),
    ("PaymentAdmission", "payment.admission.v1", "payment/admission.v1.json", None),
    ("PaymentOutcome", "payment.outcome.v1", "payment/outcome.v1.json", None),
    ("PaymentObservation", "payment.observation.v1", "payment/observation.v1.json", None),
    ("ReallocatePayment", "allocation.reallocate.v1", "allocation/reallocate.v1.json", None),
    ("UsageFact", "usage.fact.v1", "usage/fact.v1.json", None),
    ("UsageReceipt", "usage.receipt.v1", "usage/receipt.v1.json", None),
    ("PrepareObligation", "obligation.prepare.v1", "obligation/prepare.v1.json", None),
    ("EnsureBillingOccurrence", "occurrence.ensure.v1", "occurrence/ensure.v1.json", None),
    ("AuthorizeCollection", "collection.authorize.v1", "collection/authorize.v1.json", None),
    ("BilliumQuery", "billium.query.v1", "query/query.v1.json", None),
    ("PaymentStatus", "ledger.payment_status.v1", "query/payment-status.v1.json", None),
    ("DueObligations", "ledger.due_obligations.v1", "query/due-obligations.v1.json", None),
    ("EventEnvelope", "billium.event.v1", "event/event.v1.json", None),
    ("PaymentEffectOutcome", "payment.effect.outcome.v1", "payment/effect-outcome.v1.json", None),
    ("EventCatalog", "billium.event.catalog.v1", "event/catalog.v1.json", None),
    ("EventEnvelopeV2", "billium.event.v2", "event/event.v2.json", None),
    ("ExecutionRepairEvent", "billium.event.v3", "execution/repair-event.v1.json", None),
    ("ExecutionCertifiedNoEffectEvent", "billium.event.v4", "execution/certified-no-effect-event.v1.json", None),
    ("FeedSelection", "billium.feed.selection.v1", "feed/selection.v1.json", None),
    ("FeedPage", "billium.feed.page.v1", "feed/page.v1.json", None),
    ("FeedBootstrap", "billium.feed.bootstrap.v1", "feed/bootstrap.v1.json", None),
    ("MetricVersion", "usage.metric.v1", "usage/metric.v1.json", None),
    ("UsageFactV2", "usage.fact.v2", "usage/fact.v2.json", None),
    ("UsageReceiptV2", "usage.receipt.v2", "usage/receipt.v2.json", None),
    ("AggregateSnapshot", "usage.aggregate.v1", "usage/aggregate.v1.json", None),
    ("QuantitySnapshot", "usage.quantity.v1", "usage/quantity.v1.json", None),
    ("PriceVersion", "rating.price.v1", "rating/price.v1.json", None),
    ("CommercialPeriod", "period.commercial.v1", "period/commercial.v1.json", None),
    ("RatedBasis", "rating.basis.v1", "rating/basis.v1.json", None),
    ("EnsureBillingOccurrenceV2", "occurrence.ensure.v2", "occurrence/ensure.v2.json", None),
    ("PrepareObligationV2", "obligation.prepare.v2", "obligation/prepare.v2.json", None),
    ("NoChargeReceipt", "occurrence.no_charge.v1", "occurrence/no-charge.v1.json", None),
    ("FindReceipt", "receipt.find.v1", "receipt/find.v1.json", "request"),
    ("ReceiptResult", "receipt.find.v1", "receipt/find.v1.json", "response"),
    ("Capabilities", "billium.capabilities.v1", "capabilities/capabilities.v1.json", None),
    ("CapabilitiesV2", "billium.capabilities.v2", "capabilities/capabilities.v2.json", None),
    ("AllocationReceiptResult", "ledger.allocation_receipt.v1", "ledger/allocation-receipt.v1.json", None),
    ("ObservationCustody", "ledger.observation_custody.v1", "ledger/observation-custody.v1.json", None),
    ("CommandReceipt", "billium.command_receipt.v1", "command/receipt.v1.json", None),
    ("FeedOpen", "billium.feed.open.v1", "feed/open.v1.json", None),
    ("FeedRead", "billium.feed.read.v1", "feed/read.v1.json", None),
    ("FeedBootstrapRequest", "billium.feed.bootstrap_request.v1", "feed/bootstrap-request.v1.json", None),
    ("FeedBootstrapAck", "billium.feed.bootstrap_ack.v1", "feed/bootstrap-ack.v1.json", None),
    ("ObligationView", "ledger.obligation.v1", "query/obligation.v1.json", None),
    ("InvoiceView", "ledger.invoice.v1", "query/invoice.v1.json", None),
    ("AllocationQuery", "ledger.allocations.v1", "query/allocations.v1.json", None),
    ("RefundAuthorization", "ledger.refund_authorization.v1", "query/refund-authorization.v1.json", None),
    ("ExternalImport", "external.import.v1", "import/external.v1.json", None),
    ("ExternalImportManifest", "external.import.manifest.v1", "import/manifest.v1.json", None),
    ("ExternalImportPreview", "external.import.preview.v1", "import/preview.v1.json", None),
)

KINDS = {"integer": "int", "string": "string", "object": "object", "array": "list", "boolean": "bool"}

# A oneOf schema picks its envelope class by the presence of this member.
VARIANT_SELECTOR = {
    "payment.find.v1": ("status", "response"),
    "receipt.find.v1": ("status", "response"),
}


@dataclass(frozen=True)
class Member:
    name: str
    kind: str  # money | int | string | object
    required: bool


@dataclass(frozen=True)
class Envelope:
    class_name: str
    schema: str
    source: str  # schema file relative to the revision's schema/ directory
    digest_member: str
    verify_digest: bool
    members: tuple[Member, ...]


class ExportError(Exception):
    pass


def strip(node: object) -> object:
    if isinstance(node, dict):
        return {key: strip(value) for key, value in node.items() if key not in ANNOTATIONS}
    if isinstance(node, list):
        return [strip(item) for item in node]
    return node


def load(path: Path) -> object:
    return json.loads(path.read_text(encoding="utf-8"))


class Revision:
    def __init__(self, directory: Path) -> None:
        self.directory = directory
        self.name = directory.name
        self.currencies: list[dict[str, object]] = load(directory / "currencies.json")["currencies"]  # type: ignore[index]
        self.codes: list[dict[str, object]] = load(directory / "problem-codes.json")  # type: ignore[assignment]
        self.documents: dict[str, dict[str, object]] = {}
        for path in sorted((directory / "schema").rglob("*.json")):
            relative = path.relative_to(directory / "schema").as_posix()
            if relative.startswith(("conformance/", "migration/")):
                continue
            document = load(path)
            expected = SCHEMA_BASE.format(revision=self.name, path=relative)
            if document.get("$id") != expected:  # type: ignore[union-attr]
                raise ExportError(f"{relative}: $id is not {expected}")
            self.documents[expected] = document  # type: ignore[assignment]
        self.validate_catalogs()

    def validate_catalogs(self) -> None:
        """D63: exports must agree with the exact pinned catalog and canonical payload-schema digest."""
        for path in sorted((self.directory / "event-catalogs").glob("*.json")):
            catalog = load(path)
            if not isinstance(catalog, dict) or not isinstance(catalog.get("events"), list):
                raise ExportError(f"{path.name}: invalid event catalog")
            entries = catalog["events"]
            if not entries or len({e["eventType"] for e in entries}) != len(entries):
                raise ExportError(f"{path.name}: empty or duplicate catalog types")
            for event in entries:
                ref = event["payloadSchemaRef"]
                match = re.fullmatch(r"urn:billium:spec:(\d{4}-\d{2}):([^#]+)", ref)
                if match is None:
                    raise ExportError(f"{path.name}: invalid payload schema reference")
                pinned_revision, payload_path = match.groups()
                local_ref = self.document_id(payload_path)
                if local_ref not in self.documents:
                    raise ExportError(f"{path.name}: unknown payload schema {ref}")
                _, payload = self.resolve("", local_ref)
                canonical = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
                # The catalog keeps its published URNs when the same schema is carried into a new revision.
                canonical = canonical.replace(f'"urn:billium:spec:{self.name}:', f'"urn:billium:spec:{pinned_revision}:')
                digest = "sha256:" + hashlib.sha256(canonical.encode("utf-8")).hexdigest()
                if event["payloadSchemaDigest"] != digest:
                    raise ExportError(f"{path.name}: payload schema digest differs for {event['eventType']}")
                source = next((source for _, schema, source, _ in ENVELOPES if schema == event["envelopeSchema"]), None)
                if source is None:
                    raise ExportError(f"{path.name}: catalog envelope is not enrolled")
                properties = self.documents[self.document_id(source)]["properties"]
                if properties["eventContractRevision"].get("const") != catalog["eventContractRevision"]:
                    raise ExportError(f"{path.name}: envelope catalog revision differs")
                if properties["eventType"].get("enum") != [e["eventType"] for e in entries]:
                    raise ExportError(f"{path.name}: envelope type-set differs")
                if len(entries) == 1 and properties["payload"].get("$ref") != local_ref:
                    raise ExportError(f"{path.name}: envelope payload schema differs")

    def document_id(self, relative: str) -> str:
        return SCHEMA_BASE.format(revision=self.name, path=relative)

    def bundle(self) -> dict[str, object]:
        return {key: strip(self.documents[key]) for key in sorted(self.documents)}  # type: ignore[misc]

    def bundle_text(self) -> str:
        return json.dumps(self.bundle(), ensure_ascii=False, separators=(",", ":")) + "\n"

    def resolve(self, document_id: str, ref: str) -> tuple[str, dict[str, object]]:
        target, _, pointer = ref.partition("#")
        document_id = target or document_id
        node: object = self.documents[document_id]
        for part in [p for p in pointer.split("/") if p]:
            node = node[part]  # type: ignore[index]
        return document_id, node  # type: ignore[return-value]

    def kind(self, document_id: str, node: dict[str, object]) -> str:
        """Member kind of a property schema: money | int | string | object."""
        for _ in range(16):
            if "$ref" in node:
                document_id, node = self.resolve(document_id, node["$ref"])  # type: ignore[arg-type]
                if document_id.endswith(MONEY_WIRE_PATH):
                    return "money"
            elif "const" in node or "enum" in node:
                return "string"
            elif "allOf" in node or "anyOf" in node:
                node = (node.get("allOf") or node["anyOf"])[0]  # type: ignore[index]
            elif node.get("type") in KINDS:
                return KINDS[node["type"]]  # type: ignore[index]
            else:
                break
        raise ExportError(f"{document_id}: cannot map schema {json.dumps(node)[:80]} to a member kind")

    def envelopes(self) -> list[Envelope]:
        result = []
        for class_name, schema, source, variant in ENVELOPES:
            document_id = self.document_id(source)
            if document_id not in self.documents:
                continue
            document = self.documents[document_id]
            root = document["$defs"][variant] if variant else document  # type: ignore[index]
            properties: dict[str, dict[str, object]] = root["properties"]  # type: ignore[assignment]
            required = set(root.get("required", []))  # type: ignore[arg-type]
            if properties["schema"].get("const") != schema:
                raise ExportError(f"{source}: schema const is not {schema}")
            digest_member = "contentDigest" if "contentDigest" in properties else "digest"
            closed = root.get("additionalProperties") is False
            members = tuple(
                Member(name, self.kind(document_id, value), name in required)
                for name, value in properties.items()
            )
            result.append(
                Envelope(
                    class_name=class_name,
                    schema=schema,
                    source=source,
                    digest_member=digest_member,
                    verify_digest=closed and digest_member in properties,
                    members=members,
                )
            )
        return result

    def envelope_registry(self) -> dict[str, object]:
        """schema name -> ordered variants of {class, digestMember, verifyDigest, requires?}."""
        registry: dict[str, list[dict[str, object]]] = {}
        for envelope in self.envelopes():
            entry: dict[str, object] = {
                "class": envelope.class_name,
                "digestMember": envelope.digest_member,
                "verifyDigest": envelope.verify_digest,
            }
            registry.setdefault(envelope.schema, []).append(entry)
        for schema, (member, class_kind) in VARIANT_SELECTOR.items():
            variants = registry[schema]
            for entry in variants:
                if entry["class"] == _class_of(schema, class_kind):
                    entry["requires"] = member
            variants.sort(key=lambda entry: "requires" not in entry)
        sources = {name: source for _class, name, source, _variant in ENVELOPES}
        return {
            schema: {"schemaId": self.document_id(sources[schema]), "variants": variants}
            for schema, variants in registry.items()
        }

    def registry_text(self) -> str:
        return json.dumps(self.envelope_registry(), ensure_ascii=False, indent=2) + "\n"


def _class_of(schema: str, variant: str) -> str:
    for class_name, name, _source, def_name in ENVELOPES:
        if name == schema and def_name == variant:
            return class_name
    raise ExportError(f"no envelope class for {schema} {variant}")


def pascal(code: str) -> str:
    """billium.idempotency.in_flight -> IdempotencyInFlight."""
    body = code.removeprefix("billium.")
    return "".join(part[:1].upper() + part[1:] for part in re.split(r"[._]", body))


def revision_hash(rev_dir: Path) -> str:
    lock = rev_dir / "revision.lock.json"
    return "sha256:" + hashlib.sha256(lock.read_bytes()).hexdigest()


def write_or_check(
    files: dict[Path, str],
    check: bool,
    tool: str,
    owned: tuple[tuple[Path, str], ...] = (),
) -> int:
    """Write the generated files, or with check compare them; `owned` globs name files nothing else may leave behind."""
    leftovers = sorted(
        {path for base, pattern in owned for path in base.glob(pattern) if path.is_file()} - set(files)
    )
    if check:
        stale = [
            path
            for path, text in sorted(files.items())
            if not path.is_file() or path.read_text(encoding="utf-8") != text
        ] + leftovers
        for path in stale:
            shown = path.relative_to(ROOT) if path.is_relative_to(ROOT) else path
            print(f"{tool}: {shown} is stale; run scripts/spec/{tool}.py", file=sys.stderr)
        return 1 if stale else 0
    for path in leftovers:
        path.unlink()
    for path, text in files.items():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
    return 0
