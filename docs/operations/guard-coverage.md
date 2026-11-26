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
| collection | Currency comparison in the ledger's collection obligation lock at admission | `openCase` refuses a case whose currency differs from its obligation, and a case's currency never changes; the same lock still checks customer and component |
| rating | `is_numeric` after the decimal patterns in `Dec` and `MoneyFactory` | The patterns |
| rating | Currency exponent bounds in `StrategyCatalog` | The exponent must equal the ISO scale of the currency |
| execution | Non-dispatchable fiscal route in `prepareIntent` | `configurePolicy` refuses projection-only routes for remote profiles, and embedded profiles are refused earlier in `prepareIntent` |
| execution | Embedded fiscal dispatch | Unreachable: an embedded intent is never prepared |
| execution | `initiateWithIdempotency` in `OriginalRequestReplay` | `CapabilityDetails` allows idempotent initiation only together with a retention period, which is checked next to it |
| execution | Namespace state under the lock in `importOriginalIdentity` | Concurrency recheck: recovery holds the namespace before it imports |
| execution | Archived currency against the merchant currencies, and child currency against the parent, in `DatabaseExecutionStore` | The original dispatch passed the same checks, the merchant revision is immutable, and the archive must authenticate |
| usage | Correction pin and predecessor checks in the v1 `normalize` step | `validateCorrection` refuses the same facts |
| rating | Terms digest beside the price digest on republication | The price digest covers the terms digest |
| rating | Disabled reopening in `UsageAggregateAdapter::latePolicyHold` | Freezing a second snapshot refuses with the same `late_rejected` code |

## Real but untested

These checks guard reachable inputs but need a database fixture larger than a minimal test. They are the next candidates for coverage.

- execution: `ExecutionCapacity` reserve and settle amount checks; financial hold in `CommitDispatch`; repair review currency; the fiscal insert-race idempotency branch; the merchant-field checks next to the full capability comparison in `VerifyDelivery` and `ExecutionReconciliation`; lookup capability checks in `OriginalIdentityRecoveryService` and the refund-lookup check in `OriginalRequestReplay`, which need a merchant frozen without lookup through the full restore drill.
- documents: per-line arithmetic checks that only fail with two or more lines; renderer and storage binding checks.
- ledger: refund rows sharing a charge operation reference in `PaymentProjection`.
