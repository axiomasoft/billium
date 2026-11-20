# Collection due workers and outcome recovery

- **Scheduling (COV-328, D56, D60, REQ-OWN-06).** The collection runtime's `DueCollectionWorker::schedule` takes the host's authenticated execution scope, mandate version, routing and collection generations. Keep `asOf` fixed across the ledger keyset pages and follow `nextCursor` even when a page authorizes no steps. Ledger selects open obligations using the authoritative `due_at` and versioned due policy. Collection selects due open cases by `next_attempt_at`, skips locked cases and rechecks the current ledger policy and outstanding amount under lock. Obligations with null `due_at` can still enter the separate explicit `authorizeCollection` path when the owner authorizes them.
- **Delivery (INV-05, D56, D63/F04).** The authorization transaction stores the step, settlement target, execution command and collection work together. Run `CollectionRecoveryWorker::run` for a bounded seller and execution route. It submits the exact stored payment identity and payload to execution. Admission and dispatch both consult the registered collection guard. A confirmed no-effect refusal closes the settlement intent through ledger and keeps the closure receipt. Inspect `billium_collection_work.last_reason` for parked items. Pre-dispatch claims use the frozen transport retry budget. Count an expired worker claim against that budget even when delivery failed before dispatch.
- **Evidence (INV-06, REQ-FAIL-17, D56, D57, D63).** After a dispatch permit commits, the worker uses the original attempt and account for lookup. It bounds automatic lookup by the frozen capability's lookup retention from dispatch start. Missing qualification, a missing lookup identity or an expired window parks the work. Unavailable lookup leaves the step unresolved. Execution journals and queues provider evidence for its reducer even when the collection lease expires. The reducer and qualified ledger delivery must apply the matching outcome before collection advances. A provider decline requires the host's explicit classification of the verified material before a dunning policy can authorize another business step. Once execution and ledger have applied late evidence, `reconcileParked` can project it without a provider call.
- **Authority (D54, D62, D63/F04).** An active, host-admitted session is required for outgoing collection work. After a seal, recovery may claim only steps with a committed dispatch permit. A new activation leaves prior-epoch unsent work parked for disposition. Queue completion and collection outcome updates verify their lease fence and financial epoch. Continue to use the execution and ledger recovery procedures for unresolved provider evidence.

## Notification outbox, host signals, and manual intervention (P7.6)

- **Notification outbox (`CollectionNotificationService` / `CollectionNotificationPort`).** Notifications are durably deduplicated by `(dunning_case_id, step_no, template_ref, template_revision, recipient_channel)` into a deterministic `message_id` in `billium_collection_notification`. Payloads and recipient refs are rejected if they contain PAN, CVV/CVC, or secret keys (enforced in PHP by `PanCvvGuard` and in PostgreSQL by SQL CHECK constraints). Host transport delivery runs strictly outside every database transaction via `CollectionNotificationPort` (`UnboundCollectionNotificationPort` fails closed with `LookupUnavailable`). Transport delivery ACKs (`delivered`) update only the notification row via `notification_revision` CAS and **never** represent business or payment success or mutate step/case/ledger state.
- **Grace, read-only, and outcome signals (`CollectionSignalService`).** Policy-driven `grace` and `read_only` thresholds (`grace_signal_after_seconds`, `read_only_signal_after_seconds`) and verified outcomes (`renewal_succeeded`, `renewal_failed`, `customer_action_required`) are persisted append-only into `billium_collection_host_signal` with `signal_only = true`. `ProductCoordinator` consumes these facts to decide host access posture; collection signals **never** delete host resources or directly mutate external grants, provisioning, or GL. Recording `renewal_succeeded` requires verified settlement (`case.status = settled` or a `succeeded` step).
- **Offline host reads and provision-only retries.** `CollectionSignalService::readHostStatus` and host `ProductCoordinator` expose explicit `HostSnapshotFreshness` (`authoritative`, `stale`, `unknown`) with `backupChargeAllowed = false`. When Billium is unreachable or a snapshot exceeds `maxStalenessSeconds`, the host reads `stale` (cached) or `unknown` (uncached/parked) provenance and **never** initiates a backup local charge. When a collection charge has settled and host provisioning fails, retry provisioning **only** — never authorize or initiate a second payment charge.
- **Manual intervention (`CollectionManualInterventionService`).** Operator overrides (`pause_case`, `resume_case`, `reschedule_next_attempt`, `cancel_case`) require an authenticated seller-scoped privilege (`collection.manual_override` or `collection.manual_override:<sellerRef>`, re-checked inside the root transaction), a non-empty bounded `reason`, exact `expectedCaseRevision` CAS, and an immutable `recoveryProofRef` + `recoveryProofDigest` (`sha256:...`). Each accepted override atomically writes an append-only `billium_collection_manual_intervention` audit repair receipt and a `manual_intervention` host signal. Manual intervention **cannot** forge payment success (`forge_payment_success`), **cannot** clear an `unknown` step or `parked_unknown` case (`clear_unknown_outcome` or any action while `unknown`), and refuses to pause or cancel a case while an authorized/dispatched step or committed dispatch permit is in flight.

## Collection alerts and response

The host monitoring owner can run this read-only PostgreSQL query against the authoritative Billium database for one authorized collection route. Bind installation, seller, environment, intent owner namespace, execution profile and scrape time in that order. The query emits one row of gauges with a fixed set of names and no label values. Keep scope identifiers, account and operation references, challenge data, provider messages and payment details out of metric labels and alert bodies. Route alerts through the host's bounded deployment targets. Scope-level drill-down belongs in the access-controlled operator console (INV-14, D56, D59, D63; owners host monitoring and Billium collection).

<!-- collection-metrics -->
```sql
WITH target(installation_ref, seller_ref, environment, intent_owner_namespace, execution_profile, as_of) AS (
    VALUES (?::text, ?::text, ?::text, ?::text, ?::text, ?::timestamptz)
)
SELECT
    COALESCE(FLOOR(EXTRACT(EPOCH FROM MAX(t.as_of - w.next_attempt_at) FILTER (
        WHERE w.status IN ('due', 'leased') AND w.next_attempt_at <= t.as_of
    ))), 0)::bigint AS collection_oldest_due_age_seconds,
    COALESCE(FLOOR(EXTRACT(EPOCH FROM MAX(t.as_of - p.committed_at) FILTER (
        WHERE (s.status = 'unknown' OR e.state = 'unknown') AND p.committed_at <= t.as_of
    ))), 0)::bigint AS collection_oldest_unknown_age_seconds,
    COUNT(*) FILTER (WHERE s.status = 'failed_terminal' AND s.decline_category IS NOT NULL)
        AS collection_classified_declines,
    COALESCE(SUM(GREATEST(w.delivery_claims - 1, 0)), 0)::bigint
        AS collection_transport_retries
FROM target t
JOIN public.billium_collection_authorization a USING (
    installation_ref, seller_ref, environment, intent_owner_namespace, execution_profile
)
JOIN public.billium_collection_work w USING (step_id, seller_ref, environment)
JOIN public.billium_collection_step s ON s.id = a.step_id
LEFT JOIN public.billium_collection_dispatch_permit p ON p.step_id = a.step_id
LEFT JOIN public.billium_execution_attempt e
    ON e.installation_ref = a.installation_ref AND e.seller_ref = a.seller_ref
    AND e.environment = a.environment AND e.attempt_ref = p.attempt_ref;
```

Queue age measures overdue work from its due time or expired lease. Unknown age measures time since the original dispatch permit for unresolved unknown steps or attempts, including parked work. Zero means no elapsed age in that category. The classified-decline and transport-retry gauges count retained steps and work rows. A transport retry is a pre-dispatch claim after the first claim of the same step. Successful provider lookups and new policy steps do not increment that gauge. Compare gauge changes within the same retention and deployment interval, and treat a failed or stale scrape as missing data. The host sets thresholds, scrape cadence and timing windows from its approved operating policy (D56, D59, D63; owners host monitoring, Billium collection and execution).

Attach this runbook's [alert response](#collection-alerts-and-response) to each rule (D59; owner host monitoring).

| Alert | Condition under host policy | Response |
|:--|:--|:--|
| Collection queue overdue | `collection_oldest_due_age_seconds` breaches its threshold | Check worker health, current admitted session, and due or leased work for the route. Use [Delivery](#collection-due-workers-and-outcome-recovery) to resume the stored command. |
| Collection outcome unresolved | `collection_oldest_unknown_age_seconds` breaches its threshold | Resolve the original attempt through qualified lookup and match the ledger checkpoint. For closed windows or an authority change, follow [execution recovery](execution-recovery.md). |
| Collection declines rising | `collection_classified_declines` increases beyond its threshold | Verify applied evidence and the classification in the frozen dunning policy. Check outstanding debt before authorizing the next eligible step. |
| Collection delivery retries rising | `collection_transport_retries` increases beyond its threshold | Inspect expired leases, guard availability and the frozen transport budget. Check the original receipt or no-effect proof before operator intervention. |

The owner of each response follows INV-04, INV-05, INV-08, D54, D56 and D63 (Billium collection, execution and ledger). For a fiscal outage, keep the allocation receipt and look up the original fiscal intent (REQ-CON-24, REQ-FAIL-24; owner Billium execution). [Fiscal recovery protocol](../architecture/fiscal-boundary.md#fiscal-intent-state-machine--recovery-protocol).

For `requires_action`, route the durable action signal to the host's customer-action handler and keep the obligation open pending verified payment (INV-08, D56; owners Billium collection and host).

## P7.7 collection pilot

Run the focused PostgreSQL fixture on the disposable test database using `PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 DB_CONNECTION=pgsql bin/php84 vendor/bin/phpunit packages/core/tests/P7/CollectionIntegrationTest.php`. The pilot uses `DeterministicPaymentDriver`, `SimulatedFiscalDriver`, signed simulated outcome delivery and a real PostgreSQL snapshot. See the [acceptance catalogue](../acceptance-catalog.md#p77--collection-pilot) for the checkpoint and receipt matrix. Live-provider qualification and activation have separate gates (COV-AC-519, D59; owner release).
