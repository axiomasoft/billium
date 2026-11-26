# Universal External Consumer & Bridge Contract Kit

Status: `PLAN1.P9.5` (`D17`, `D21`, `D23`, `D25`, `D26`, `D31`, `D55`, `D58`, `D59`, `D60`, `D61`, `D62`, `D63`, `D71`).

Billium ships a self-isolated, product-agnostic protocol, fixture corpus (`CT-01…CT-20`), dev-only fault testkit, loopback runtime runner, and external report validator for external consumers and execution/accounting bridges. Concrete external consumers and production bridge deployments live outside the Billium repository and integrate solely through Billium's public HTTP, wire-envelope, feed, and SPI contracts.

## Isolation & Ownership Invariants (`D17`, `D58`, `D71`)

1. **Zero external host artifacts in Billium**: Billium contains no external consumer business tables, no foreign database readers/writers, no product-specific SDKs, and no production bridge lifecycle code.
2. **Product-agnostic & future-extensible**: Contracts, fixtures, reports, and testkit adapters make no assumptions about any specific downstream commerce/subscription host and allow additive evolution under `spec/revisions/<rev>/`.
3. **Authority source vs package delivery separation**: Every contract case (`CT-01…CT-20`) explicitly distinguishes:
   - `authoritySource` (`remote-billium`, `standalone-billium`, or `billium` for public event feeds),
   - `executionProfile` (`remote-billium` vs `external-executor` where Billium `execution` is disabled),
   - `packageDelivery` (`php-contracts-current` vs `php-contracts-archive-matrix`).
   Transport or SDK package delivery never grants execution or observation authority.
4. **Immutable original execution ownership (`COV-008`, `COV-010`, `PB-01`, `PB-12`, `PB-14`, `PB-21`)**:
   - When an external executor owns an operation (`ledger-only` composition), Billium consumes authenticated `payment.observation.v1` facts for projection/allocation only and never creates a second PSP execution journal or hidden retry worker (`CT-16`).
   - Across `AuthorityRouter` epoch barriers (`CT-20`) and merchant/credential rotations (`CT-11`), only new business intents adopt the new route; in-flight unknown operations, lookups, and refunds permanently resolve to the original charge executor and merchant account.

## Adapter Protocol (`billium.consumer.bridge.protocol.v1`)

Schema: `fixtures/integration/consumer/schema/protocol.json`.

External consumer adapters run as independent OS processes (or remote endpoints wrapped by a thin CLI shim) speaking JSON-Lines over `stdin`/`stdout`:

1. Runner sends `start` (`boundary`: `double | loopback | external-runtime`, `producerUrl`, `workDir`).
2. Consumer replies `ready` with `adapter`, `adapterVersion`, `commitHash`, `packageHash`, `specRevision`, `specHash`, `deliveryProfile`, `supportedOps`, and `paidPspEffect: false`.
3. Runner drives `step` commands (`http_command`, `replay_prepared`, `preflight_command`, `feed_bootstrap_and_ack`, `feed_tail_sync`, `accounting_consume_events`, `host_provisioning_drill`, `archive_decode_matrix`) and validates `result` observations against `fixtures/integration/consumer/vectors/CT-01.json` … `CT-20.json`.
4. Runner sends `stop` for clean shutdown.

## Source Feed, Snapshot Bootstrap & Committed Cursor Contract (`D52`, `D63`)

External event and observation consumers must follow the four-step recovery-safe cursor contract:

1. **Explicit catalog pin (`feed/open`)**: Pin `eventContractRevision`, `envelopeSchemas`, and `eventTypes` (`selectionMode = explicit`). Enabling new modules on Billium never mutates an existing `selectionDigest`; changing selected types requires a new subscription, never cursor reuse.
2. **Certified snapshot + lease (`feed/bootstrap`)**: Reserve a `leaseRef` under the outbox retention barrier, verify `snapshotDigest` over scope, selection, snapshot items, and `nextCursor`, and apply all snapshot items idempotently by economic identity (`aggregateRef`, `aggregateRevision` / `sourceFactRef`).
3. **Atomic cursor commit before lease ack (`feed/bootstrap/ack`)**: Persist durable item dispositions and `committedCursor` atomically before acknowledging `leaseRef` + `snapshotDigest` (`204`).
4. **Contiguous tail & retention floor recovery (`feed/read`)**: Advance `committedCursor` monotonically across filtered empty pages (`CT-09`). If outbox pruning advances the retention watermark past a stale unpinned cursor, Billium returns `423 billium.recovery.required` (never `200` empty or `absent`), requiring re-bootstrap (`CT-18`). Observation source partition gaps park in custody (`parked_gap`), and lineage changes transition the partition to `recovery_required` until staged `BootstrapPartition` adoption completes (`CT-08`).

## Contract Suite Matrix (`CT-01…CT-20`)

| Case | Invariant Verified |
|:--|:--|
| `CT-01` | Execute duplicate / lost `202 CommandAccepted` response replays exact prepared bytes and `commandKey` with one PSP call |
| `CT-02` | Same `commandKey` with altered semantic payload returns `409 billium.idempotency.conflict`; existing receipt rechecks current auth (`403` on revoked/foreign caller, `receipt: none`) |
| `CT-03` | Unsupported command major (`payment.execute.v2`) and unknown fields on closed commands rejected before wire send and at server ingress (`400`) |
| `CT-04` | Cumulative `payment.observation.v1` totals (`30.00` -> `60.00`) assign rather than add deltas; duplicate callbacks preserve stable receipt |
| `CT-05` | Out-of-order stale outcome does not regress totals; refund observation arriving before original charge parks on dependency and drains to `refunded = 30.00` |
| `CT-06` | Multi-process PostgreSQL refund race enforces `amount <= captured − confirmed − reserved` (`409 billium.refund.capacity_exceeded`, zero over-refund) |
| `CT-07` | Charge `100.00` with cumulative `refundedTotal = 30.00` plus separate `refund` observation `30.00` produces canonical `refunded = 30.00`, never `60.00` (`D41`, `INV-06`) |
| `CT-08` | Observation partition sequence gap parks (`parked_gap`) without premature projection; regressed/changed `sourceLineage` enters `recovery_required` and recovers via staged `BootstrapPartition` |
| `CT-09` | `feed/bootstrap` + `feed/read` crash before cursor commit replays `snapshot + tail` with stable economic identity and zero duplicate consumer effects |
| `CT-10` | Two sellers sharing identical `operationRef`, `intentRef`, and `obligationRef` stay strictly isolated; cross-seller reads/writes deny without leaking existence |
| `CT-11` | Default merchant route switch (`stop_new` -> `replacement`) and credential rotation preserve original merchant account for historical lookup and refund |
| `CT-12` | Transport custody ACK (`receipt: none`), `CommandAccepted`, `ProviderOutcomeRecorded`, `ProjectionApplied`, and `AllocationReceipt` remain independent |
| `CT-13` | Cloned/restored PostgreSQL attachment and retired host egress session fence stale workers before any PSP dispatch (`provider.calls == 0`, `423` on unactivated clone) |
| `CT-14` | Duplicate event deliveries through `AccountingMapper` / `FinBridgeExportEnvelope` produce multiple delivery ACKs, a single applied receipt per stable `sourceFactRef`, and zero GL writes |
| `CT-15` | Current (`N`) and pinned prior (`N−1`) contracts archives preserve unknown response fields in full-envelope RFC 8785 `digest`, reject tampered extensions, and refuse `currentOnlySchemas` on `N−1` |
| `CT-16` | External-executor (`ledger-only`) profile disables `execution` (`features.execution = false`, no hidden retry worker) while accepting authenticated observations (`COV-008`, `COV-010`, `PB-01`, `PB-14`) |
| `CT-17` | Host provisioning outage after settled payment triggers provision-only retry (`chargeTriggered = false`) and zero extra Billium operations or PSP charges |
| `CT-18` | Outbox retention floor gap returns `423 billium.recovery.required` (never `absent`) and recovers cleanly through `feed/bootstrap` + `feed/bootstrap/ack` |
| `CT-19` | Pre-dispatch `payment.cancel.v1` prevents worker PSP call (`known-terminal`), post-dispatch cancel refuses (`409`), and late signed provider success on cancelled auth is retained as `held_discrepancy` (`late_positive_after_no_effect`) |
| `CT-20` | `AuthorityRouter` epoch cutover routes only new intents to `remote-billium` while pre-cutover unknown operations and post-cutover refunds stay bound to their original executor (`PB-12`, `PB-21`) |

## Evidence Boundaries & Report Validation (`D59`, `V951`, `V952`)

Reports conform to `fixtures/integration/consumer/schema/report.json` (`protocol = billium.consumer.contract.report.v1`, `evidence_class = consumer-contract`) and are verified by `conformance/consumers/bridge/check-report.py`:

- `boundary = double`: Dev-only `FaultInjectingPaymentDrivers` (`provider-double`) and `TransportFaultAdapter` (`transport-contract`). Cannot satisfy `localRequiredSuiteComplete`, `externalReady`, or `rolloutGatePassed`.
- `boundary = loopback`: Local real PHP 8.4 + PostgreSQL + HTTP loopback producer + reference consumer (`V951`). Satisfies `localRequiredSuiteComplete = true` when all `CT-01…CT-20` pass, while keeping `externalReady = false` and `rolloutGatePassed = false`.
- `boundary = external-runtime`: Runs against an independent external consumer deployment (`--consumer-cmd`).
  - When no authorized external consumer environment is available, the runner emits `status = "pending"`, `externalReady = false`, and `rolloutGatePassed = false` so affected remote rollout stays blocked while local required suites remain complete.
  - `paidPspTransactions` is strictly `0` across all contract runs; no paid PSP transaction is ever implicit.

### Commands

```bash
# Run full CT-01..20 loopback contract suite on real PHP + PostgreSQL (V951)
docker compose run --rm toolchain bash scripts/test/consumer-contract.sh \
  --boundary loopback --suite all --report conformance/reports/consumer-loopback.json

# Validate positive and negative report fixtures & hash/boundary rules (V952)
docker compose run --rm toolchain python3 conformance/consumers/bridge/check-report.py \
  --fixture conformance/consumers/bridge/tests/reports
```
