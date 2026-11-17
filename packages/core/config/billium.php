<?php

declare(strict_types=1);

// D45/D60: unset retention refuses pruning; batches never share an economic root Tx.
return [
    'connection' => null,
    'installation_ref' => env('BILLIUM_INSTALLATION_REF'),
    // D62: every root Tx has a hard deadline; a seal waits for admitted roots only up to its lock timeout.
    'runner' => ['max_attempts' => 3, 'root_deadline_ms' => 30000],
    'financial_session' => ['seal_lock_timeout_ms' => 5000],
    'idempotency' => ['lock_timeout_ms' => 5000],
    'outbox' => [
        'relay_batch' => 100,
        'claim_seconds' => 60,
        'retention_floor_seconds' => null,
        'delivery_backoff_seconds' => 5,
        'delivery_budget' => 10,
        'maintenance_connection' => null,
    ],
    'feed' => [
        'drain_batch' => 100,
        'bootstrap_lease_seconds' => 60,
        'bootstrap_batch' => 100,
        // D63: host-provisioned, independent of copied database state; app.key is the fallback.
        'signing_key' => null,
    ],
    'inbox' => [
        'retry_backoff_seconds' => 5,
        'retry_budget' => 10,
        'handler_compatibility' => [],
        'retention_floor_seconds' => null,
    ],
    'runtime_role' => ['preflight' => true],
    'ledger' => ['refund_reconcile_after_seconds' => 3600],
    // D55/D56: optional usage admission is discovered only when explicitly enabled; boot never installs SQL.
    'usage' => ['enabled' => false],
    'rating' => ['enabled' => false],
    'schedule' => ['enabled' => false],
    'execution' => ['release_evidence_ref' => null, 'retention_policy_ref' => null, 'outgoing_enabled' => false],
    // D55: desired module set; unlisted optional modules create no routes, workers or tables.
    'modules' => [
        'composition' => 'embedded-commerz',
        'enabled' => ['platform', 'ledger'],
    ],
];
