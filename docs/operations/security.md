# Security and Privacy Operations

This runbook defines the baseline security, isolation, redaction, and supply-chain invariants enforced by Billium (PLAN1.P10.6, D71/D72).

## 1. Authentication, Scope Precedence, and Seller Isolation

1. **Authentication and scope checks precede policy and handlers.** Every HTTP request is authenticated via `Authenticator`, resolved to an immutable `ExecutionContext` via `SellerContextResolver`, and checked against route/payload `sellerRef`, `environment`, `installationRef`, and `operationRef` before `AuthorizationVerdict` or any command/query handler runs.
2. **Untrusted headers are ignored.** Client-supplied headers (such as `X-Seller-Ref`) never override the authenticated principal's resolved `ExecutionContext`.
3. **Opaque refusals (no existence oracle).** Cross-seller lookups, tampered scope fields, and missing resources return identical `403` (`billium.auth.denied`) or `503`/`400` responses without disclosing whether a foreign resource exists.
4. **Pagination cursors are seller-scoped.** Reusing another seller's `nextCursor` is rejected identically to a malformed cursor (`400`, `billium.validation.invalid`).
5. **Replays require current authorization.** Replaying an already-accepted `commandKey` after `AuthorizationVerdict` switches to `Deny` returns `403` (`billium.auth.denied`, `receipt=none`) and never reveals the prior `receiptRef`.

## 2. Manual Repair Authorization

Privileged reconciliation and repair operations (`RepairCommands::repair`) require explicit caller grants and fail closed with `billium.auth.denied` when grants are missing or insufficient, writing zero rows to `billium_execution_repair_receipt`.

## 3. Webhook Authenticity, Replay, and Hash-Conflict Protection

1. **Authenticity before effect.** Unverified or forged provider callbacks fail closed with `billium.auth.denied` and are quarantined in `billium_execution_provider_work` (`state='quarantined'`) with zero writes to `billium_execution_provider_fact` or `billium_execution_capacity`.
2. **Idempotent replay.** Re-delivering an authentic callback returns the same `commandKey` and produces at most one reduced provider fact.
3. **Hash-conflict quarantine.** A callback carrying an existing event identity with an altered payload/status is quarantined (`custody_quarantined`) while preserving the original `payload_digest` and confirmed terminal state.

## 4. Payload Limits, Metadata Bounds, and Secret Redaction

1. **Bounded HTTP payloads.** Requests exceeding `billium-server.max_body_bytes` or `max_depth` are rejected with `400` (`billium.validation.invalid`) before command processing. A declared `Content-Length` above the limit is refused before the body is read; otherwise the body stream is read in 64 KiB chunks and refused as soon as it passes the limit, so at most `max_body_bytes + 1` bytes are copied into memory. Host middleware that reads JSON bodies before routing (for example Laravel's global `TrimStrings` and `ConvertEmptyStringsToNull`) buffers the whole body first: exclude the `/billium/` routes from it and cap the body size at the web server or PHP (`client_max_body_size`, `post_max_size`) as well.
2. **Bounded & sanitized metadata.** Invoice metadata is limited to 64 KiB (`65536` bytes) and guarded by PostgreSQL `CHECK` constraints (`SQLSTATE 23514`) that reject inline secret keys (`token`, `apiKey`, `secret`) while permitting opaque `secret_ref` locators.
3. **Opaque `SecretRef` discipline.** `Billium\Execution\Data\SecretRef` accepts only `secret://...` locators and rejects raw credential material.
4. **Zero inline secret persistence.** `CallbackFrame` / `CallbackCustody` never persists raw PAN, CVV/CVV2, `password`, `api_key`, or `client_secret` inline in `billium_execution_provider_evidence.sanitized_payload`; sensitive frames are routed to the external `CallbackPayloadArchive` and referenced only by `secure_payload_ref`.
5. **Error responses never echo secrets.** Validation and authorization error envelopes (`400`/`403`) never echo rejected credential locators or sensitive field values.

## 5. Verification Commands

```bash
# P10.6 security & privacy sanity gate (real PostgreSQL 16)
docker compose run --rm -u 0:0 toolchain vendor/bin/pest --filter=BillingSecurity

# Supply-chain, license policy, and release hold sanity check
docker compose run --rm -u 0:0 toolchain bash scripts/quality/release-supply-chain.sh

# Profile hold enforcement check (fails nonzero when live activation holds are unresolved)
docker compose run --rm -u 0:0 toolchain bash scripts/quality/release-supply-chain.sh --profile remote-live
```
