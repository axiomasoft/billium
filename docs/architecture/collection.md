# Billium Collection Module Architecture (`billium-collection`)

The `billium-collection` optional module (`L2`, namespace `Billium\Collection\`, schema stream `billium.collection`) owns versioned saved-method consent proofs (`ConsentBinding`), durable `DunningCase`-rank consent/cancellation guard state and pre-operation tombstones, immutable original-binding refund route provenance, and `requires_action` customer-action receipts. It never stores raw PAN/CVV/card secrets, never runs autonomous provider subscription renewal schedulers, and never owns host entitlements or grants.

## Module & Table Ownership

Dependencies declared in `spec/modules.json` and `CollectionPlugin`:

- **Required modules**: `platform`, `ledger`, `execution`.
- **Required host port**: `CollectionConsentGuard` (`Billium\Collection\Contracts\CollectionConsentGuard`). Unbound default (`UnboundCollectionConsentGuard`) fails closed (`ProblemCode::LookupUnavailable`).
- **Config gate**: `billium.collection.enabled` (`false` by default). Disabled compositions (`embedded-commerz`, `ledger-only`, or omitted/disabled `collection` or missing `execution`) register zero collection providers, workers, or routes.

Owned tables (`public.billium_collection_*`, migrations exclusively under `packages/core/database/migrations/collection/`):

| Table | Purpose | Immutability / Backstop |
| :-- | :-- | :-- |
| `billium_collection_consent_binding` | Versioned immutable consent/mandate proof & reference (`seller_ref`, `environment`, `billing_account_ref`, `customer_ref`, `consent_ref`, `consent_version`, `payment_method_ref`, `token_locator`, `merchant_account_ref`, `merchant_config_revision`, capability snapshot provenance, allowed purposes/currencies, caps, validity window, `source_evidence_digest`, `proof_digest`) | Append-only (`billium_append_only` trigger; runtime has `SELECT, INSERT` only); SQL CHECK constraints forbid raw PAN digit sequences and enforce cap/validity bounds. |
| `billium_collection_consent_guard` | Mutable serialization head locked at `LockRank::DunningCase` (rank 60, segment 0) tracking `active_binding_id`, `active_consent_version`, monotonic `guard_revision`, monotonic `source_revision`, monotonic `cancellation_generation`, `status` (`active`, `revoked`, `cancelled`), and local revocation receipt/effectiveness timestamp | Trigger `billium_collection_consent_guard_check()` forbids `DELETE`, immutable identity changes, non-monotonic revisions, and revival from terminal `revoked`/`cancelled` back to `active`. |
| `billium_collection_guard_tombstone` | Append-only durable revocation/cancellation receipts and pre-operation tombstones (`consent_revoked`, `host_cancelled`, `authorization_tombstone`) keyed by `(seller_ref, environment, tombstone_ref)` and optional `target_authorization_ref` / `target_business_intent_ref` | Append-only (`billium_append_only` trigger; runtime has `SELECT, INSERT` only). |
| `billium_collection_customer_action` | Durable `requires_action` customer-action projection and receipt (`three_ds_challenge`, `mandate_confirmation`, `redirect_authentication`, `sca_challenge`) | `status IN ('requires_action', 'expired', 'superseded')` — never `paid` and never terminal failure; trigger `billium_collection_customer_action_guard()` forbids deletion, field mutation, and revival. |
| `billium_collection_refund_route` | Frozen charge-to-original-binding refund route provenance (`operation_ref -> original_binding_id, original_merchant_account_ref, original_merchant_config_revision, ...`) | Append-only (`billium_append_only` trigger; runtime has `SELECT, INSERT` only). Changing a billing account's active consent binding never alters historical refund routing. |

## Core Invariants (P7.1)

1. **Proof/Reference Consent Owner without PAN/CVV**: `ConsentBinding` stores opaque provider/vault token locators and external mandate proof digests only. `PanCvvGuard` and SQL CHECK constraints reject PAN-like digit runs, CVV/CVC values, and sensitive keys before persistence.
2. **Merchant & Seller Isolation + Saved-Method Capability**: `SavedMethodCapabilityValidator` requires exact `(sellerRef, environment, merchantAccountRef, merchantConfigRevision)` agreement with `MerchantRevision` and its matching `CapabilitySnapshot`, enforcing `EffectKind::Charge`, `DriverCapability::SavedMethod`, `DriverCapability::RecurringConsent` (for recurring/dunning/usage purposes), currency support, and `QualificationMode::ProviderVerified` in `live` environments. A token bound to another seller or merchant is forbidden for new charges; moving a consent to a new merchant requires a new consent version with fresh verified evidence.
3. **Immutable Proof vs Mutable Guard State**: `billium_collection_consent_binding` is strictly immutable. Current revocation and host cancellation generation live in `billium_collection_consent_guard` + `billium_collection_guard_tombstone`. Revocation or cancellation accepted before any execution operation exists persists durable guard state, `source_revision`, `cancellation_generation`, and a `RevocationReceipt` whose `effectiveAt` timestamp defines when local revocation became effective.
4. **DunningCase-Rank Serialization & Out-of-Transaction Host Authority**: Consent binding supersession, revocation/cancellation, and downstream admission/dispatch validation serialize on the same `billium_collection_consent_guard` row via `LockSet` at `LockRank::DunningCase` (rank 60, segment 0) — never `->lockForUpdate()`. External `CollectionConsentGuard::currentAuthority()` calls run strictly outside every database transaction (checked across `transactionLevel()`, `getRawPdo()`, and `getRawReadPdo()`).
5. **Original Refund Route Preservation & `requires_action` Separation**: Rotating an account's consent binding to a new version or merchant never alters `billium_collection_refund_route` for prior charges (`assertRefundRouteMatchesOriginal` refuses new-merchant refund routing). `requires_action` produces a `CustomerActionReceipt` with `paid = false` and `terminalFailure = false`.

## Verification

```bash
docker compose run --rm -u 0:0 toolchain vendor/bin/pest --filter=CollectionConsent
docker compose run --rm -u 0:0 toolchain vendor/bin/pest --filter=ModuleBoundaries
docker compose run --rm -u 0:0 toolchain php bin/packman gate
```
