# Guard Coverage on Money Paths

Each refusal guard on the money-path modules (ledger, collection, documents, execution, platform, usage, rating, schedule) was checked by disabling it and running the owning module's tests. Guards whose removal left the suite green received a minimal test that fails without them. This page lists the guards that stay without a dedicated test and why, so a reviewer can tell an intentional backup from a gap.

## Redundant by construction

These checks cannot fail on their own: another check on the same path refuses every input they refuse. They stay as cheap fail-fast copies or as defence in depth.

| Module | Guard | Backed by |
| :--- | :--- | :--- |
| ledger | `#` in a credit-note reference (`IssueCreditNoteCommand`) | The credit-note routine refuses the same reference |
| ledger | `ReverseAllocationCommand` row count and row shape | Each other; the allocation reference is unique |
| ledger | Negative total in `InvoiceStatus::derive` | `0 <= settled <= total` |
| ledger | Unknown intent in `DatabaseLedgerParticipants` | The routine raises `reference.unknown` |
| ledger | Credit-note part kind in receipt decoding | Only credit notes write `credit-note:` receipt references |
| ledger | `dueAt` cursor pattern | The strict `createFromFormat` round-trip |
| ledger | `CommandPayload` amount ranges, negative line tax, mixed line currencies | The SQL routines refuse the same values |
| documents | Credit-note series type | The numbering participant refuses with the same detail |
| documents | Sum, tax and total triples on draft, snapshot and line | Any two imply the third, and `subtotal + tax = total` is checked on attach |
| documents | Stored finalization fields and line serialization | Equal to the snapshot validated on attach; callers require `SnapshotReady` |
| documents | Negative line amount in `BcDocumentTaxArithmetic` | Non-negative discount not above the amount |
| documents | Second partial-credit refusal after the first throws | Unreachable |
| documents | Void and finalize rechecks under the row lock | Concurrency rechecks of state read before the lock |
| platform | Manifest digest in `activate()` | The authorization digest; `verify()` relies on the check and is tested |
| usage | `is_numeric` before the decimal pattern | The pattern |
| execution | Root admission namespace check (`AdmitRoot`) | `insertBinding` rechecks state and version under lock; an open namespace without a completeness receipt is forbidden by a table `CHECK` |
| execution | Storage-layer rechecks in `AdmissionStorage` (namespace state, child kind, reason, category, positive amount, root currency) | The command layer validates the same plan first |
| execution | Driver id, contract version and snapshot reference in `QualifiedAdapter` | The driver is resolved by the merchant's id and version, the snapshot by its reference, and the driver's own snapshot is compared in full |
| execution | Wire type after the codec round-trip (`AuthorizedPayments`, `RefundPayments`) | `$wire::class !== $command::class` |
| execution | Same-code currency scale on child payments | `Money` uses the canonical scale per currency |
| execution | Refund root kind | A root is always a charge or an authorization |
| execution | Same amount with a different binding on root replay | The binding carries the command's semantic digest |
| rating | `is_numeric` after the decimal patterns in `Dec` and `MoneyFactory` | The patterns |
| rating | Currency exponent bounds in `StrategyCatalog` | The exponent must equal the ISO scale of the currency |

## Real but untested

These checks guard reachable inputs but need a database fixture larger than a minimal test. They are the next candidates for coverage.

- execution: capability lineage rechecks in `VerifyDelivery` and `ExecutionReconciliation` (the same comparisons as `QualifiedAdapter`, which is tested); `ExecutionCapacity` reserve and settle amount checks; financial hold in `CommitDispatch`; the fiscal orchestrator (correction currency, route and trust-strategy support, lookup page bounds), which has no feature test; repair review currency.
- execution, no test reaches the code at all: `ArchivedExecutionIdentity`, `OriginalIdentityRecoveryService`, `OriginalRequestReplay` and the archive checks in `DatabaseExecutionStore`.
- documents: per-line arithmetic checks that only fail with two or more lines; renderer and storage binding checks.
- ledger: refund rows sharing a charge operation reference in `PaymentProjection`.
- usage: snapshot-reference and pinned-fact checks in `DatabaseUsage` and `DatabaseAggregationParticipants`.
- rating: booking, price store and occurrence checks (`DatabaseRatingBooking`, `DatabasePriceVersionStore`, `DatabaseOccurrenceService`, `UsageAggregateAdapter` reopen policy).

## Known behaviour

`OpenDunningCase` accepts a currency that differs from its obligation; only collection admission refuses the case later.
