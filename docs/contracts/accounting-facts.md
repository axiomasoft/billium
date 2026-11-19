# Accounting Facts and Optional FinBridge Export Contract

Billium emits immutable, replay-safe economic accounting facts through the platform-owned
`Billium\Platform\Contracts\Accounting\AccountingMapper` boundary (`AccountingFactMapper`).
The mapper operates strictly on authenticated public source records (`AccountingSourceRecord`)
and is available whenever `platform` is enabled — independently of the optional `documents` module.

## Fact Taxonomy and Category Separation

Accounting facts strictly separate actual cash movements from bounded ledger receivables,
noncash allocations, and noncash discrepancy exposures:

| Category (`AccountingFactCategory`) | Fact Kinds (`AccountingFactKind`) | Authoritative Input Basis | Double-Counting Guard |
| --- | --- | --- | --- |
| `cash_in` | `payment.captured` | Confirmed actual provider capture effect (`providerAccountRef` + `providerEffectRef`) | One fact per unique actual provider effect; never multiplied by allocations or retries. |
| `cash_out` | `refund.succeeded` | Confirmed actual provider refund effect (`providerAccountRef` + `providerEffectRef`), including exceptional over-refunds | Never derived from bounded `refundedTotal` or allocation events; unknown/pending/failed-terminal/zero-amount/ambiguous aggregate inputs produce zero confirmed cash facts. |
| `receivable` | `invoice.issued`, `credit_note.issued`, `write_off.recorded` | Bounded ledger projections only (`bounded !== false`, `projectionState !== 'held_inconsistent'`) | Corrections/credit notes/write-offs link to the original fact via `originalFactRef`. |
| `noncash_allocation` | `allocation.applied`, `allocation.reversed` | Ledger allocation and reversal receipts | Strictly noncash; multiple allocations of one capture produce one `cash_in` fact and separate `noncash_allocation` facts. |
| `noncash_exposure` | `exposure.over_refund`, `exposure.over_capture`, `exposure.discrepancy` | Complete discrepancy basis with contributing effect identities, revisions, and digests | Linked via `linkedCashFactRefs` and `originalFactRef`; never counted again as outgoing cash (`cash_out`). |

All monetary values use integer minor units (`Money`) and canonical major-unit decimal strings (`Money::amount()`).
Floating-point representations are forbidden across the contract surface.

## Stable `sourceFactRef` Identity Rules

`sourceFactRef` identifies the economic effect, never the transport delivery attempt, outbox row ID,
callback count, or aggregate revision:

1. **Actual Cash (`cash_in`, `cash_out`)**:
   - Canonical SHA-256 fingerprint over `(sellerRef, environment, factKind, providerAccountRef, providerEffectRef)`.
   - Independent of `deliveryAttemptRef`, `aggregateRevision`, and allocation count.
   - Duplicate callbacks or lookups for the same actual provider effect yield the exact same `sourceFactRef`.
   - Two distinct confirmed refunds (e.g., ACC-RST-12 `70 + 70` over a `100` capture) yield two distinct `cash_out` facts totaling `140`.
2. **Noncash Exposure (`noncash_exposure`)**:
   - Canonical SHA-256 fingerprint over `(sellerRef, environment, factKind, rootOperationRef, reason, currency, exposureMinor, sorted(contributingEffects))`.
   - Contributing effects (`effectRef`, `revision`, `digest`) are canonically sorted before hashing so any replay permutation yields the exact same `sourceFactRef`.
   - Links to contributing cash facts via `linkedCashFactRefs` and parent fact via `originalFactRef` without duplicating cash totals.
3. **Bounded Receivables & Noncash Allocations (`receivable`, `noncash_allocation`)**:
   - Canonical SHA-256 fingerprint over `(sellerRef, environment, factKind, economicEffectRef)`.
   - Reversals, credit notes, write-offs, and replacements create a new economic fact linked to its predecessor through `originalFactRef`; replaying an existing fact never creates a new ID.

## FinBridge Boundary and Explicit ACK / Applied Receipt Separation

`AccountingMapper::toFinBridge()` translates `AccountingFactEnvelope` (`billium.accounting.fact.v1`)
into a deterministic `FinBridgeExportEnvelope` (`finbridge.fact.export.v1`):

- **No GL ownership**: Billium never resolves chart-of-accounts codes, never constructs debit/credit
  journal lines, and never writes GL tables. Forbidden GL metadata keys are stripped before export.
- **No premature package extraction**: The mapper remains internal to `billium-platform` plus the
  deterministic `FakeFinBridgeConsumer` test double in `billium-testkit`. A standalone `billium-finbridge`
  package is deferred until a live downstream FinBridge consumer contract is formally confirmed.
- **Independent delivery ACK vs. applied receipt**:
  - `AccountingConsumer::acknowledgeDelivery()` records transport-level `AccountingDeliveryAck` per
    `(sourceFactRef, deliveryAttemptRef)`.
  - `AccountingConsumer::apply()` records economic-level `AccountingAppliedReceipt` idempotently by
    `sourceFactRef` (`replayed = true` on subsequent deliveries).
  - Consumer outage, backpressure, or absence of an accounting consumer never blocks or rolls back
    core ledger commits or execution state transitions.

## Unsupported Schema Quarantine and Post-Upgrade Recovery

When `AccountingMapper::map()` encounters an unregistered `AccountingSourceRecord::$schemaVersion`:

1. It returns `AccountingMappingStatus::QuarantinedUnsupportedSchema` carrying a `QuarantinedFactRecord`
   with a deterministic `quarantineRef` and the intact original `AccountingSourceRecord`.
2. Consumers store quarantined records via `AccountingConsumer::quarantine()` without dropping them.
3. After an explicit upgrade registers the new input schema via `AccountingMapper::withAdditionalInputSchemas()`,
   replaying quarantined records maps and applies them once without creating duplicates.

## Extension and Selection Gates (COV-299 & COV-547)

- **COV-299 (`AccountingExportExtension`)**: Accounting export extends Billium strictly through the
  outbox/source mapper and independent consumer boundary. The extension negotiates `AccountingMapper`
  capability presence and FinBridge export contract versions (`finbridge.fact.export.v1`), refuses
  unsupported contract versions with `billium.schema.unsupported`, maintains only extension-owned
  delivery/backpressure state, and never alters core financial migrations or writer ownership.
- **COV-547 (Named Design / Selection Gate)**: Accounting export is documented and validated as an
  explicit design/selection gate with a reproducible conformance fixture (`fixtures/vectors/accounting/facts-and-exposure.json`),
  deterministic test double (`Billium\Testkit\Accounting\FakeFinBridgeConsumer`), and end-to-end
  exceptional-effect proof (`AccountingFactsTest`, `AccountingExceptionalEffectsTest`). No live
  production FinBridge ERP connector or GL writer is claimed until an explicit external consumer
  contract is ratified.
