# Ledger reads and bootstrap

`Billium\Ledger\Contracts\LedgerQueries` provides authenticated reads using the host `CallerContext` and
`billium.connection`. Each call opens an independent PostgreSQL `REPEATABLE READ READ ONLY` transaction;
calling it inside an existing transaction fails. Reads never register receipts, drain observations, look up a
provider or reserve a refund. Missing references and references outside the authenticated installation,
seller or environment return `billium.reference.unknown` (REQ-CON-14, INV-14, D35, D60).

The direct methods return arrays; amounts are `MoneyWire` arrays with string `amount` and `currency`.
The arrays are deliberate: each result is the `result` member of a published response schema
(`ledger.payment_status.v1`, `ledger.due_obligations.v1` and the other query results), the HTTP server passes
it through unchanged, and unknown optional members must survive a roundtrip (BC-ENV-27). Typed value objects
would duplicate those schemas in PHP, drop members a newer revision adds, and change a `1.0` API for every
caller, so the schema stays the single contract and `dispatch` output is validated against it in tests.
`obligation` includes original due, signed adjustment, allocation, credit, open balance, frozen references,
revision and due policy provenance. `invoice` includes stored snapshots, line obligations, totals and open
balance; its legal number remains null until the documents module owns it. `receipt(receiptRef)` returns
the original business receipt body. Replaying an allocation with the original command key yields that same
receipt, which can then be retrieved without another money operation (REQ-CON-14, D29, INV-12, INV-13,
ACC-RST-05).

`dispatch(json)` validates `billium.query.v1` using the contracts codec and checks its installation, seller
and environment against the caller. Over HTTP every query kind uses the one host action `ledger.query`.
The host policy still decides per kind: `AuthorizationVerdict::authorize` receives the validated `query` and
`selector` in `ResourceScope::selectors`, so a host can allow `paymentStatus` and deny `refundAuthorization`
(`AuthContextTest`). Separate actions per kind would only rename what hosts already see and would break
every host policy that allows `ledger.query` today. Mismatches return `billium.auth.denied`. Selector names are
`paymentRef`, `obligationRef`, `invoiceRef`, `operationRef` for `allocationByOperation`, and
`billingAccountRef` for `dueObligations`. An optional `sourceAuthority` disambiguates a payment/operation.
An authority-free selector matching multiple sources returns `billium.validation.invalid`; it never chooses
an arbitrary source. Applied state, spendable funds and receipt associations use the selected source throughout.
`allocationByOperation` includes only receipts linked by the selected payment's journal/revision rows or its
observation's immutable economic digest, within the payment's account and currency. This includes legacy
zero-part receipts: economic digests include authority. A bare operation reference is insufficient.
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

`dueObligations(accountRef, asOf, limit, cursor)` returns eligibility candidates with a scoped keyset cursor;
`limit` is 1 to 1000, the same ceiling as the event feed.
It requires open positive obligations, nonnull `due_at <= asOf`, and
`collection_eligible_at <= asOf` when present. Ordering is `(due_at, id)`; cursors bind installation, seller,
environment, account and `asOf`. A null due date never falls back to creation or invoice time. A known
account with no candidates returns an empty list; an account absent from ledger obligations is an unknown
reference. Candidates grant no collection authorization (D60).

`Ledger::amendObligationDuePolicy(AmendObligationDuePolicy)` replaces the existing policy through a keyed
Active root command. The command requires the obligation's billing account and complete policy provenance,
including when `dueAt` is null. It increments the obligation revision and stores the policy in an immutable
receipt with an `obligation.adjusted.v1` outbox event in the same transaction. Replays return that receipt even
after later amendments; a reused key with another policy conflicts. The creation receipt remains unchanged.
Replaying the original `RecordObligation` payload under another key returns its original creation receipt after
an amendment. Creation replay compares the immutable creation digest and identity; a changed creation payload
conflicts even when it happens to match the amended live policy.

Payment projections use `(seller, environment, sourceAuthority, operationRef)` across accepted lineage changes.
New observation and refund-detail receipts include the URL-encoded source authority and operation reference;
long identities use a SHA-256 key. Existing receipts retain their identity on replay. Refund details resolve only
their own authority's original charge. Refund reconciliation persists each payment's last comparison time and
selects the least recently compared eligible payments first, so bounded runs advance and revisit changed bases.
Dependency retries likewise wait for the original charge of the refund's own authority. Another authority's
same-ref charge does not consume the parked refund's retry budget.

`Allocate.paymentRef` and `ReallocatePayment.paymentRef` accept the source identity
`payment:<URL-encoded sourceAuthority>:<URL-encoded paymentRef>`, for example
`payment:remote-billium:op-1`. A bare reference still works when exactly one payment matches. Ambiguous
selectors return `billium.validation.invalid`. This uses the existing v1 string field; wire fields and strict
decoding are unchanged. Reallocation receipt identity and semantic digest include the resolved source and
refund total; receipt bodies retain `payment_id`, `payment_ref` and `source_authority`.
Older reallocation receipts replay only when their release journal identifies the selected payment uniquely.

Observation partition bootstrap digests cover the complete manifest namespace (channel, authority, lineage,
seller, environment and partition), cursor and all ordered items. `BootstrapPartition::digestOf` requires
that namespace as its third argument. Every item's namespace, operation and revision must match the manifest;
installation must match the caller. Verification precedes staging and also applies to resume. Resume additionally
checks the retention lease, count and cursor. Database guards reject cross-namespace items, late inserts and
advancement of an incomplete manifest. A valid matching snapshot can then apply its items and adopt its cursor.

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
