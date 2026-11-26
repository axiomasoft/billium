# Restore, Recovery, and Rolling Upgrade Operations

This runbook summarizes the operational invariants and rehearsal commands for restoring a Billium PostgreSQL database and executing rolling upgrades (P10.5, building on [execution recovery](execution-recovery.md) and [migration drills](../migrations/restore-upgrade-drills.md)).

## 1. Restore into Recovery (Zero Auto-Dispatch)

A restored PostgreSQL snapshot or cloned database carries its copied `billium_financial_session` state, which may still show `active`. Billium never trusts a copied database flag to authorize outgoing effects:

1. **Fence the predecessor first.** Before switching traffic or restoring into production, close old admission (`Installation::beginSealing()` -> `Installation::seal()`), drain in-flight roots, and retire old egress dispatch tickets at the external authority (`RetirementProof`).
2. **Quarantine by default.** Boot the restored attachment with a new host `attachmentGeneration` (or without a writer token during inspection). Financial mutations and provider dispatch fail closed with `billium.installation.quarantined` or `billium.recovery.required`, producing **zero** outgoing provider calls.
3. **Preserve raw custody.** `FinancialMode::RawCustody` (callbacks, immutable provider evidence) remains permitted while quarantined or in recovery so late external facts are never lost.
4. **Equal `commit_no` never auto-activates.** Comparing `max(commit_no)` in `billium_money_commit_log` (lower, equal, or greater) is strictly diagnostic (D62). Even after a clean seal with identical `max(commit_no)`, `Installation::openSession()` opens the new epoch in `recovery`, never `active`.
5. **Explicit certified activation.** After reconciling post-backup provider effects, unknown attempts, and downstream deliveries, adopt an unexpired external `ActivationCertificate` bound to the predecessor `RetirementProof` via `Installation::activateSession()`. Superseded epochs cannot be reactivated, and runtime database roles cannot update `billium_financial_session` directly (`SQLSTATE 42501`).

## 2. Rolling Upgrade and Non-Destructive Rollback

Schema and worker upgrades follow a strict **expand -> migrate/backfill -> validate -> contract** discipline (REQ-FAIL-29):

1. **Expand first:** Add nullable columns and `CHECK (...) NOT VALID` constraints before deploying new-generation workers. Never alter or drop immutable monetary columns (`billium_allocation`, `billium_allocation_receipt`, `billium_money_commit_log`, `billium_execution_operation`).
2. **Mixed-version window:** Old-generation workers continue writing cleanly against the expanded schema (`NULL` for new metadata columns). If an N-1 worker encounters an unknown future enum/status value, it fails closed before acquiring a dispatch fence or calling any provider.
3. **Backfill & validate:** Backfill only new additive metadata in bounded batches and run `VALIDATE CONSTRAINT` once complete.
4. **Non-destructive code rollback:** Reverting application code to N-1 during the rollback window preserves all committed monetary facts, receipts, and bindings; defer any contract `DROP COLUMN` until the rollback window has closed.

## 3. Running the Drills

Run the deterministic rehearsal drills inside the repository `toolchain` container against PostgreSQL 16:

```bash
# Restore, old-worker fencing, certified activation, and retention floors (fake provider)
docker compose run --rm -u 0:0 toolchain bash scripts/drills/billing-restore.sh --fake-provider

# Mixed-version compatibility and non-destructive expand-contract rollback
docker compose run --rm -u 0:0 toolchain bash scripts/drills/billing-upgrade.sh

# Full P10.5 Pest recovery drill suite
docker compose run --rm -u 0:0 toolchain vendor/bin/pest --filter=BillingRecoveryDrill
```

Both drill scripts emit a concise JSON evidence manifest on stdout (and optionally to `--report <path>`) and exit `0` only when all assertions pass.
