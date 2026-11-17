# Payment execution acceptance evidence

The catalogue defines 49 P5 IDs. The phase register also tracks 147 adopted coverage obligations, 14 runtime invariants and 50 explicit deliverables. Use the scenario and run records together. A `Group` attribute is a selector. An executed test with a verified predicate is the witness. The preparation baseline discovered one group of 49 and ran no P5 runtime acceptance. The following matrix records the available runtime witnesses and their supported scope; the final candidate still requires its full CI and independent reviews.

## Catalogue witnesses

| ID | Runtime witness and scope |
| --- | --- |
| ACC-CON-01 | Twenty distinct process/backend pairs in `AdmissionIdentityTest` submit one command key with two input digests. Ten accepted replies share one retained receipt and ten changed replies receive `IdempotencyConflict`. PostgreSQL retains one binding, operation, attempt and wakeup. |
| ACC-CON-02 | `AdmissionIdentityTest` races keys for the same independently resolved commercial intent. One durable binding and attempt survive. |
| ACC-CON-03 | `RefundCapacityTest` starts 20 workers behind the original charge lock in each scenario. It checks reservation/attempt counts and independently expected remaining capacity. |
| ACC-CON-04 | Four committed workers in `ExecutionAcceptanceRacesTest` contend on the pending charge. Refund sees the captured amount after the callback wins or refuses before capture. |
| ACC-CON-05 | Eight callback and lookup workers in `ExecutionAcceptanceRacesTest` arrive at the same apply barrier. One material revision survives and conflicting provider material is quarantined. |
| ACC-CON-06 | `ExecutionBoundaryRacesTest` runs current sender finish, stale sender finish and reducer in three independent PHP/PostgreSQL processes. Observed lock waits serialize the current CAS before stale finish and application; one attempt remains and stale authenticated evidence survives. |
| ACC-CON-07 | `DispatchCancelTest` stops a child process after admission or dispatch start. Cancel wins with zero sends in the first case. The started case refuses cancel and preserves one send and the later signed effect. |
| ACC-CON-15 | `ExecutionRestoreTest` interleaves old dispatch, clone dispatch and authority retirement in three processes against two databases. Both fence orderings are checked: the clone has zero dispatch starts and collection permits; an old request committed before retirement is reconciled. |
| ACC-CON-18 | Twenty distinct wire keys race across two independently authenticated sellers for the same commercial intent in `AdmissionIdentityTest`. One seller owns the only binding and effect. The losing seller receives typed conflicts without an original operation reference. |
| ACC-CON-20 | Four independent participants in `ExecutionAcceptanceRacesTest` race refund admission, signed observation, allocation and advisory against one fully paid invoice. They assert committed and rolled-back reservation cases, no premature allocation and conservation through explicit reallocation. |
| ACC-CON-22 | `ExecutionRestoreTest` places a copied active database beside the live owner with one independently retained authority/egress journal. The copied attachment cannot initiate; scoped recovery and certified activation are required. |
| ACC-CON-23 | `ExecutionBoundaryRacesTest` joins credential rotation, callback A, callback B with the same external identity and historical refund in four independent PostgreSQL processes. A stale credential is refused, the approved replacement credential stays on original account A, and cross-account material is quarantined. |
| ACC-CON-25 | `HistoricalRefundRoutingRaceTest` observes PostgreSQL blocking refund admission behind the route switch in a second process. The committed refund and provider request retain the charge's original binding. A raw SQL child insert with the replacement binding is rejected. |
| ACC-RST-01 | `UnknownResultTest` kills the process after committed admission. The original command key returns the persisted receipt ID and operation and the provider journal shows no send before recovery. |
| ACC-RST-02 | `UnknownResultTest` kills the process after the provider has accepted. Recovery uses one lookup and the same request identity, while the external journal retains one initiate and PostgreSQL retains one attempt. |
| ACC-RST-03 | `ExecutionRestoreTest` uses actual PostgreSQL dumps older than provider effects and downstream delivery. Original identity import, qualified inventory and reducer recovery restore the lost effect; re-delivery to the independent receiver applies no second economic entry. |
| ACC-RST-04 | `ExecutionRestoreTest` restores a copied active row while the original owner still exists. Missing, stale and forged process or transition evidence cannot enable the clone; provider sends and collection permits remain zero before certified recovery. |
| ACC-RST-08 | `NamespaceRetentionTest` repeats exact old keys after general receipt prune and namespace closure. The retained original still replays, while the previously refused key without an original returns `KeyExpired` with zero sends. `BusinessContextIdentityTest` rejects deletion of a retained financial tombstone. The catalogue's destructive tombstone-prune sequence remains distinct from these supported paths. |
| ACC-RST-12 | `LateRefundRepairTest` links the certified original release, paid replacement, authenticated late success and the same inventory discrepancy to a protected operator repair receipt/outbox. The reviewed case closes while observed 140, applied 70, exposure 40 and the financial hold remain intact; review does not repair inconsistent money or authorize compensation. |
| ACC-RST-13 | `ExecutionRestoreTest` advances cancellation and customer-credit allocation after backup, restores the older copy and rejects ordinary financial admission. A real sequence/commit interleaving also demonstrates equal maximum counters with different committed effects; only scoped recovery and external certification permit activation. |
| PB-02 | `EvidenceReducerTest` admits via the public command, sends once, processes signed material, and relays through the real qualified receiver into the ledger. Provider and external host journals are simulated. |
| PB-03 | `AdmissionIdentityTest` repeats the same key for the same admitted body and immutable receipt. `UnknownResultTest` checks recovery/replay preserve one provider effect and one attempt. |
| PB-04 | `AdmissionIdentityTest` changed-key-digest race gets the typed deterministic conflict with one admitted operation. |
| PB-05 | `UnknownResultTest` loses the local reply after admission and recovers the original. `OutcomeProjectionBoundaryTest` reads the command key and business receipt cold. |
| PB-06 | `UnknownResultTest` retains unknown across accepted timeout, expired key TTL and ambiguous lookup, and sends no replacement. |
| PB-07 | `ProviderFactIdentityTest` and `CallbackCustodyTest` replay indistinguishable signed evidence while one material revision remains. |
| PB-08 | `ProviderFactIdentityTest` retains conflicting signed material while preserving the original accepted revision and economics. |
| PB-09 | `HistoricalMerchantRefundTest` repeats by transport and business keys with one reservation and one provider refund. `LateRefundSuccessTest` checks retained amount, cumulative and separate child detail after restart. |
| PB-10 | `OutcomeDeliveryTest` loses the acknowledgement after ledger application. A cold relay retries the immutable command, with a single application and no additional provider calls. |
| PB-11 | `ExecutionRestoreTest` detects the actual restored inbox/outbox gap against independent provider and receiver journals. The original immutable command replays once, and a lost post-backup root is recovered under its original reference. |
| PB-13 | `WriterAdmissionProtocolTest` fences retired egress. `StaleWorkerTest` retains the delayed response while a replacement worker looks up the original attempt. |
| PB-16 | `RefundCapacityTest` and `LateRefundSuccessTest` demonstrate reserved and confirmed capacity under concurrent refunds and unknown across provider retention expiry. |
| PB-17 | `OutcomeProjectionBoundaryTest` denies foreign seller, owner, installation and source. `OutcomeDeliveryTest` and `CallbackCustodyTest` retain invalid or unlinked evidence without a financial effect or disclosure. |
| PB-18 | `NamespaceRetentionTest` kills a writer at a pre-commit trigger and checks zero binding, attempt, receipt, work and provider call. A retry creates one attempt. `UnknownResultTest` checks recovery after committed admission. |
| PB-19 | `NamespaceRetentionTest` preserves original binding and receipt across new keys and closure. Changed terms conflict and a missing original in a closed namespace is expired. |
| PB-20 | `ExecutionRestoreTest` keeps the physically restored clone fenced despite its copied active metadata. New commands, outgoing effects and collection steps require the independently adopted current attachment and certified namespace coverage. |
| PB-22 | `DispatchCancelTest` proves that unsent cancellation prevents dispatch, a started charge refuses cancellation, and the resulting signed capture is applied. A subsequent explicit refund is a separate child, and admission does not send it automatically. |
| PB-23 | `FiscalAccountingHandoffTest` advances cumulative capture from 60 to 80 while the root stays succeeded. `QualifiedObservationHandoffTest` gates qualified tail adoption on a complete authenticated snapshot. `FilteredFeedBootstrapTest` refuses replay below the floor. |
| PB-24 | `LateRefundSuccessTest` preserves actual and exceptional exposure after local replacement with no automatic economic replacement. `EvidenceReducerTest` and `ProviderFactIdentityTest` preserve the last admissible projection. |
| PF-01 | `AdmissionIdentityTest` races commercial identity and `NamespaceRetentionTest` verifies atomic binding, capacity, attempt, receipt and work rollback. |
| PF-02 | `ProviderWorkRestartTest` and `StaleWorkerTest` retain durable signed evidence before acknowledgement and across a stale sender finish. |
| PF-03 | `UnknownResultTest` and `ProviderWorkRestartTest` need independently verified retirement and exact complete window before recording certified absence. |
| PF-04 | `QualifiedOriginalReplayTest` verifies the independently archived original request, exact body and account, current qualification and live authority within the provider window. Unqualified or stale inputs refuse before send. |
| PF-05 | `CallbackCustodyTest` proves merchant-scoped event identity, bounded rejection and retained custody before acknowledgement. |
| PF-06 | `ProviderFactIdentityTest` keeps terminal provider revisions and the associated signed amount separate from local capacity and projection. |
| PF-07 | `NamespaceRetentionTest` and `BusinessContextIdentityTest` preserve original business identity outside general receipt retention. |
| PF-08 | `ExecutionRestoreTest` recovers post-backup provider effects through independently authenticated original dispatch/source archives. Missing or substituted identity leaves recovery held, with zero new sends. |
| PF-09 | `NamespaceRetentionTest` proves closed-namespace protection. `ExecutionRestoreTest` imports the authenticated original root/child identity and key once, including original-ID-only lookup; general P9 import remains in its own phase. |
| PF-11 | `LateRefundSuccessTest` and `ProviderFactIdentityTest` preserve excess fact amount and original provenance when local capacity or projection rejects ordinary consumption. |

## Runtime invariants

| ID | Witness |
| --- | --- |
| RT-01 | `AdmissionIdentityTest` compares exact receipt bodies and IDs for 20 equal-key processes and typed conflict for digest changes. |
| RT-02 | `AdmissionIdentityTest` and `NamespaceRetentionTest` preserve a single durable business binding across fresh transport keys and retained history. |
| RT-03 | `UnknownResultTest` and `StaleWorkerTest` compare original attempt/ref/digest, takeover and independent provider send counts. |
| RT-04 | `WriterAdmissionProtocolTest` rejects authority calls inside the transaction and proves the post-commit send permit. `QualifiedObservationHandoffTest` refuses transport authentication in a raw PDO transaction. |
| RT-05 | `RefundCapacityTest` and `AuthorizationLifecycleTest` check unknown reservations remain held across TTL, missing signed amount and authorization expiry. |
| RT-06 | `ProviderWorkRestartTest` proves immutable evidence survives a process kill, lost acknowledgement and sender lease expiry with one material application. |
| RT-07 | `EvidenceReducerTest` and `FiscalAccountingHandoffTest` compare exact cumulative assigned totals and separate child detail, including reordered duplicate input. |
| RT-08 | `RefundCapacityTest` and `CaptureVoidCapacityTest` use distinct real PostgreSQL processes to check original basis and reservations conserve capacity. |
| RT-09 | `ProviderFactIdentityTest` and `LateRefundSuccessTest` retain actual excessive effects and discrepancies beyond admissible allocation. |
| RT-10 | `FinancialSessionSealTest` and `LedgerOnlyRestoreAdmissionTest` check committed mutation, stale or absent session authority, seal and permitted raw custody. |
| RT-11 | `DeploymentOwnerSessionTest`, `ActivationCertificateTest` and `ExecutionRestoreTest` check independent owner admission, adoption, physical clone refusal and guarded recovery with durable external journals. |
| RT-12 | `AllocationSamePaymentTest`, `CrossSellerAllocationTest`, `AllocationBackstopTest` and `AllocationVsObservationTest` check capacity and scope through public and SQL entry points. |
| RT-13 | `EvidenceReducerTest` rolls back material state, work and factual outbox together. `QualifiedObservationHandoffTest` verifies ledger application and replay independently of transport acknowledgement. |
| RT-14 | `NamespaceRetentionTest`, `FilteredFeedBootstrapTest` and qualified inventory fail explicitly on missing history, absent overlap or incomplete evidence. |

## Evidence layers and checkpoints

The run records identify timestamps, source and dependency fingerprints, exact commands, exit codes and assertions. These selected results overlap and carry no additive test total.

| Subject | Executed scope and checkpoint |
| --- | --- |
| Contract and provider doubles | `CONTRACTS` records 155 passing tests with 535 assertions on the contract candidate, including deliberately unsupported modes. |
| Original admission and unknown recovery | `DISPATCH` records 38 tests and 830 assertions, then 45 and 596 on the separate scoped selectors. 20-process admission cases compare before/after binding, operation, attempt and provider journal counts. The after-accept crash starts with one external call and recovers through one lookup with the external count still one. |
| Capture and void | `CAPTURE_VOID` records 40 tests and 983 assertions plus a targeted test with 46 assertions. Two 20-process races check exact reservation/capacity and one call per accepted effect. The reversed-callback case authorizes 100 and captures 30 and 40, which yields ledger allocation 70. |
| Refunds and exposure | `REFUNDS` records 16 tests and 420 assertions, including two 20-process refund scenarios. The original/replacement case preserves observed 140, applied 70 and exposure 40 in the separate retained values. |
| Evidence and projection | `EVIDENCE_CAPACITY` records 19 tests and 507 assertions. `DELIVERY_QUERIES` records 43 tests and 112077 assertions, including the repository structural tests. Real execution admission and custody use qualified simulated responses to move authorization 100 through capture 60, capture 20, refund 10 and authenticated ledger application. Independent delivery retries have no extra provider calls. |
| PostgreSQL restore | `RESTORE` records 8 tests and 235 assertions, six `pg_dump` and seven `pg_restore` invocations into new databases, independent provider/receiver/authority journals, exact before/after counts and both three-process fence orderings. This is a logical PostgreSQL restore rehearsal; production PITR remains a deployment qualification. |
| Joined finish, rotation and repair | `REMAINING_BOUNDARIES` records 18 tests and 621 assertions for the three-/four-process cases, same-discrepancy operator adjudication, original refund regressions and affected reconciliation. |
| Operational startup and receipt provenance | `COMPOSITION_OPS` records 14 startup tests and 279 assertions; `ORIGINAL_BINDING_AND_SBP` records 26 tests and 451 assertions for current readiness, immutable receipt provenance, cold queries and simulated named adapter selection. |
| Selected actual provider | The shipped evidence uses explicitly simulated providers. COV-388–395 and COV-517 are enforced deployment prerequisites: actual provider/account qualification and operational dossiers must be supplied before enabling a production owner; these tests grant no live qualification. |

The phase register binds every COV and explicit deliverable to its owner, matching assertion evidence or an unresolved gap. `COMPOSITION_OPS` carries the current candidate mapping with exact report and test paths. Use [payment execution operations](execution.md) to prepare a provider dossier and [reconciliation and recovery](execution-recovery.md) for the operational drill.
