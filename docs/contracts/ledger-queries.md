# Ledger reads and bootstrap

`Billium\Ledger\Contracts\LedgerQueries` provides authenticated reads using the host `CallerContext` and
`billium.connection`. Each call opens an independent PostgreSQL `REPEATABLE READ READ ONLY` transaction;
calling it inside an existing transaction fails. Reads never register receipts, drain observations, look up a
provider or reserve a refund. Missing references and references outside the authenticated installation,
seller or environment return `billium.reference.unknown` (REQ-CON-14, INV-14, D35, D60).

The direct methods return arrays; amounts are `MoneyWire` arrays with string `amount` and `currency`.
`obligation` includes original due, signed adjustment, allocation, credit, open balance, frozen references,
revision and due policy provenance. `invoice` includes stored snapshots, line obligations, totals and open
balance; its legal number remains null until the documents module owns it. `receipt(receiptRef)` returns
the original business receipt body. Replaying an allocation with the original command key yields that same
receipt, which can then be retrieved without another money operation (REQ-CON-14, D29, INV-12, INV-13,
ACC-RST-05).

`dispatch(json)` validates `billium.query.v1` using the contracts codec and checks its installation, seller
and environment against the caller. Mismatches return `billium.auth.denied`. Selector names are
`paymentRef`, `obligationRef`, `invoiceRef`, `operationRef` for `allocationByOperation`, and
`billingAccountRef` for `dueObligations`. An optional `sourceAuthority` disambiguates a payment/operation.
`readRender` returns `billium.validation.invalid` here; rendering belongs to P6. The v1 `queryKind` enum
does not contain receipt: receipt retrieval uses the direct `receipt` method (REQ-CON-14, INV-14;
`spec/revisions/2026-10/schema/query/query.v1.json`, `common/defs.json#/$defs/queryKind`).

`paymentStatus(paymentRef)` reads charge operations. It returns last consistent `applied` separately from
ledger-owned actual `observed`, financial hold reasons and discrepancy references, overcapture/overrefund
exposure, spendable funds, projection status, pending allocation reason, bootstrap and reconciliation
status. A missing settlement intent leaves capture visible with no applied projection. Holds, missing
application, feed recovery and unfinished bootstrap expose zero spendable. Confirmed refund reallocation
also blocks spendable funds until the separate policy command completes. Pending and unknown provider
states retain those meanings. Refund children remain detail linked to the original charge in snapshots;
they never add to its refunded total (REQ-CON-14, INV-06, D35, D41, D57, D60).

Reconciliation reports stored unresolved discrepancies and otherwise conservatively reports pending when
refund totals exist. The current reconciliation producer does not persist a successful completeness
certificate: reads therefore omit `lastCompletedAt` and `completenessCursor`, rather than infer completion
from elapsed time or a feed cursor (D48, D54, D60).

`refundAuthorization(paymentRef, amount)` is advisory. Normal capacity is `captured - refunded` from
applied totals. For an amount within that capacity, required reallocation is
`max(0, allocated - (captured - refunded - amount))`, including any existing deficit. A null amount asks
about refunding the remaining normal capacity; wire dispatch accepts optional selector `amount` as a
decimal string in the charge currency. An amount above capacity is not allowed and its normal release is
bounded by the allocated amount. Hold or noncurrent projection also refuses advice. P5 owns execution
reservations; reading this result grants no reservation (D35, REQ-CON-14).

`dueObligations(accountRef, asOf, limit, cursor)` returns eligibility candidates with a scoped keyset cursor.
It requires open positive obligations, nonnull `due_at <= asOf`, and
`collection_eligible_at <= asOf` when present. Ordering is `(due_at, id)`; cursors bind installation, seller,
environment, account and `asOf`. A null due date never falls back to creation or invoice time. A known
account with no candidates returns an empty list; an account absent from ledger obligations is an unknown
reference. Candidates grant no collection authorization (D60).

The ledger registers five `billium.snapshot_sources` keys already declared by the event catalog:
`ledger.obligations`, `ledger.invoices`, `ledger.allocations`, `ledger.payments`, and
`ledger.discrepancies`. Sources read on the feed's supplied consistent read-only connection, retain stable
identities and content digests, original observed/applied revision history, account bindings, journal and
credit note relationships, immutable receipts, and source bootstrap/recovery flags. They use stored
snapshots, never current pricing. The platform retains the bootstrap floor pin through transfer and
acknowledgement; consumers adopt durable dispositions before acknowledging and replaying the tail (D60,
D63, REQ-CON-27).

Targeted checks in the toolchain:

```bash
docker compose run --rm toolchain php vendor/bin/phpunit packages/core/modules/ledger/tests/Queries/LedgerQueriesTest.php
docker compose run --rm toolchain php vendor/bin/phpunit packages/core/modules/ledger/tests/Queries/LedgerSnapshotSourceTest.php
docker compose run --rm toolchain php vendor/bin/phpunit packages/core/modules/ledger/tests/Feature/LostAllocationResponseTest.php
docker compose run --rm toolchain php vendor/bin/phpunit packages/core/modules/ledger/tests/Feature/ObligationDuePolicyTest.php
docker compose run --rm toolchain php vendor/bin/phpunit packages/core/modules/ledger/tests/Feature/PaymentProjectionQueryTest.php
```
