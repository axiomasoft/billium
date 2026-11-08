# Outbox delivery and recovery

D52/D60/D63 implementation lives in platform. The host implements `EventPublisher`, which receives the immutable
message and destination outside a database transaction. `billium:outbox:relay --limit=100` claims public work;
`--commands` selects the separate internal command lane. Token-CAS acknowledgements, bounded exponential backoff
and parking preserve event/command identity and payload digest. Requeue through the corresponding relay service
resets the failure budget, preserving lifetime attempts and immutable content (BC-EVT-04/05). A claim that expires
without an outcome (worker died or hung in `publish`) counts as a failure, so a message that keeps killing its worker
parks at the delivery budget. `billium:outbox:relay` reports the exception class on failure and passes the exception
to the host's `report()`; the message never reaches the console or the database.

Run `billium:event-contracts:register --database=<owner-connection>` explicitly during installation/upgrade after
migrations. It applies the configured module manifests; ordinary boot never registers events. Runtime cannot alter
platform tables or write the registry. Disabling a previously installed module preserves historical registrations
and readers. The original seven v1 registrations are backfilled by the platform migration (D63).

The host provisions `billium.feed.signing_key` (at least 32 bytes), or the existing Laravel `app.key`. Scope and
selection changes require a new subscription. An empty page can advance its authenticated `nextCursor`; persist it
after validating the page. Feed position is independent of observation sequence and database freshness (BC-ENV-32).

Module snapshot readers implement `SnapshotSource` and register under `billium.snapshot_sources`. They read only
their selected state on the supplied snapshot connection. An unavailable selected reader fails closed. Ledger's
production reader belongs to P3.10; this implementation does not manufacture a replacement. Replay-only streams
start from retained history and cannot bootstrap below the conservative replay floor (D60/D63).

The receiver stages the complete bootstrap, verifies its snapshot digest, records durable dispositions and atomically
adopts its cursor before `acknowledgeBootstrap`. The source keeps the lease through this acknowledgement. Expiry or
missing retained tail gives `billium.recovery.required`; begin a new bootstrap. Required subscriptions pin their last
acknowledged custody floor; `acknowledgeCursor` advances it only after durable custody (BC-ENV-32). A scope holds at
most 16 open required subscriptions; `closeFeed` ends one irreversibly and releases its pins. A bootstrap whose
snapshot fails releases its own pin immediately. The lease is fixed at reservation (`billium.feed.bootstrap_lease_seconds`)
and covers snapshot build, transfer and acknowledgement together: size it for the largest snapshot.

`billium:outbox:prune` refuses an unset `billium.outbox.retention_floor_seconds`. Configure an explicit privileged
`billium.outbox.maintenance_connection`; the worker connection cannot delete content. Prune serializes with pin
reservation and removes only a complete D52-safe prefix whose destinations are delivered and whose retention and
consumer/lease pins permit deletion. Work is done in transactions of at most 1000 rows, each taking the exclusive
barrier briefly, so reads and acknowledgements proceed between batches. The replay watermark commits with each deletion. Outbox is a queue, not the
financial archive; the host chooses retention policy and numerical horizons (D25/D52/D60).

The additive upgrade refuses noncatalog legacy event types and ambiguous installation history; it never renames or discards immutable facts (D63). `EventFeedReader::diagnostics()` exposes the safe horizon, observable blocker age (nullable) and physical consumer lag without treating them as freshness evidence (D52).
