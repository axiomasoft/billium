# Generic Source-Side Export Contract (`source.export.v1`)

Billium is strictly self-isolated (D71): it contains no external product dependencies, no external product names,
and no direct reads from foreign databases. External hosts own their source-side exporters, run them inside their
own process under their own authorization, and deliver immutable, sanitized export artifacts or local streams to
Billium.

Machine-readable field requirements, profile contracts, and explicit missing-evidence decisions are versioned in
[`docs/migrations/source-mapping.json`](source-mapping.json) and verified via:

```bash
python3 scripts/spec/check-import-contract.py --source-mappings docs/migrations/source-mapping.json
```

## 1. Responsibility Split

| Boundary | Owner | Responsibilities | Forbidden |
| --- | --- | --- | --- |
| Source-side exporter | External host | Runs in host process with host auth; extracts historical records at an explicit `exportCutoff`; strips PII and secrets; computes canonical JCS `recordsChecksum` and `artifactDigest`; writes immutable artifact or readable stream. | Exposing raw credentials as funding authority; writing directly to Billium tables; substituting missing prices, currencies, dates, or provider IDs. |
| Export reader & profile | Billium (`GenericExportReader` + `SourceExportProfile`) | Reads only an immutable local artifact file, JSON payload, or non-network stream; verifies cutoff, checksums, sanitization policy, and absence of credentials; normalizes records into `external.import.manifest.v1` and `external.import.v1` inputs with preserved original IDs and explicit `unanchored_evidence`. | Opening network URLs or SQL/DB connections to an external host; fabricating missing money/currency/price/provider facts; expanding mutable subscriptions or non-monetary grants into monetary obligations. |
| Staging & preview | Billium (`ImportIdentityStore`, `ImportPreviewEvaluator`) | Evaluates read-only preview report (`external.import.preview.v1`) with SHA-256 digest, isolated per-currency totals (`authoritativeCurrentBalance: false`), and durable Billium-owned staging/mapping tables. | Promoting historical sums to authoritative balances without reconciliation; issuing a second Billium legal invoice number for historical invoices. |

## 2. Immutable Artifact Envelope (`source.export.v1`)

Every export artifact delivered to `GenericExportReader` MUST be a UTF-8 JSON object conforming to `source.export.v1`:

- **Scope and provenance**: `exportSchema` (`"source.export.v1"`), `profile` (`"generic"`, `"reference-recurring"`, or `"reference-credits"`), `batchId`, `installationRef`, `environment`, `sellerRef`, `sourceSystem`, `sourceInstallation`, `sourceBook`, `sourceExportRevision`.
- **Cutoff and integrity**:
  - `exportCutoff`: RFC 3339 UTC timestamp (`YYYY-MM-DDTHH:MM:SSZ`). Missing or malformed cutoff fails closed.
  - `recordCount`: exact integer count matching `records.length`.
  - `recordsChecksum`: `sha256:<64-hex>` over the RFC 8785 (JCS) canonical serialization of the `records` array.
  - `artifactDigest` (optional but verified when present): `sha256:<64-hex>` over the canonical artifact object excluding `artifactDigest`.
- **Sanitization and credential barrier**:
  - `sanitizationPolicy`: `{"piiRedacted": true, "secretsStripped": true, "policyVersion": "<bounded-ref>"}`.
  - Any credential or secret field anywhere in the artifact or record tree (`apiKey`, `secretKey`, `providerSecret`, `oauthToken`, `privateKey`, `password`, `webhookSecret`, `sourceCredential`, etc.) causes fail-closed rejection (`export.forbidden_source_credential`). An exported source credential never becomes monetary authority.
- **Historical totals**: `totalsByCurrency` isolates sums by `(sellerRef, currency, recordKind)` with `authoritativeCurrentBalance: false`. Currencies are never summed together.

## 3. Product-Agnostic Reference Profiles

1. **`GenericExportProfile` (`generic`)**: Maps normalized source records (`obligation`, `invoice_history`, `payment_evidence`, `refund_evidence`, `allocation`, `agreement_reference`, `unanchored_evidence`) strictly on proven evidence.
2. **`RecurringExportProfile` (`reference-recurring`)**: Handles recurring/subscription exports, annual funding bundles with periodic grants, historical plan changes, and historical invoices without inferring recurring charges from mutable subscription state or expanding periodic grants into obligations.
3. **`CreditsExportProfile` (`reference-credits`)**: Handles host non-monetary credits, bonus grants, loyalty points, and reward quantities without ever promoting non-monetary credits to monetary balance or non-currency units to ISO 4217 currency.

## 4. Identity Stability, Party Separation, and Mapper Versioning

- **Canonical source identity**: `(installationRef, environment, sellerRef, sourceInstallation, sourceBook, sourceRecordId, recordKind)`.
- **Versioned mapper metadata never changes identity**: Upgrading `profileVersion` or `importPolicyVersion`, changing `batchId`, or repackaging the export artifact MUST leave `sourceIdentityDigest`, `payloadDigest`, and `sourceFactRef` identical, and never renames `sourceBook` or `sourceRecordId`.
- **Party role separation**: Exports distinguish `subjectRef`, `payerRef`, `actorRef`, `customerRef`, `billingAccountRef`, and `sellerRef` inside `partyRoles` and `originalBusinessRefs`. Host customer/payer/actor identities are never conflated with `sellerRef`.
- **Original ID preservation**: Source record IDs, agreement/subscription/order/invoice/payment refs, `originalIssuer`, and `sourceNumber` are preserved verbatim.

## 5. Explicit Missing-Evidence and `unanchored_evidence` Decisions

Absence of authoritative evidence NEVER triggers synthetic defaults (`0`, default currency, current plan price, document display price, `today`, `captured`, synthetic provider IDs, or a second legal invoice number). All incomplete cases fail safe to `unanchored_evidence` (with explicit `reasonCodes` and `missingEvidence`, `collectionAllowed: false`) or `nonfinancial_agreement_reference` (for pure non-monetary host records):

| Scenario | Matrix ID | Normalized `recordKind` | `money` | `reasonCodes` / `missingEvidence` | Collection / Synthetic Obligations |
| --- | --- | --- | --- | --- | --- |
| Missing price, currency, payment, or provider refs | IM-01 | `unanchored_evidence` | `null` | `import.missing_proven_price_basis` or `import.missing_authoritative_funding_evidence` + exact missing fields | Forbidden (`0` invented obligations/captures) |
| Mutable monthly subscription without frozen price/occurrences | IM-06 | `unanchored_evidence` | `null` | `import.mutable_subscription_without_frozen_occurrences` / `["frozenPriceSnapshot", "provenOccurrences", "money"]` | Forbidden (`0` inferred recurring charges) |
| Host non-monetary credit grant (entitlement reference) | IM-07 | `nonfinancial_agreement_reference` | `null` | Non-monetary entitlement stored outside monetary balances | Forbidden (`0` monetary balance) |
| Host credit balance claimed as money without ISO currency/funding | IM-07 | `unanchored_evidence` | `null` | `import.nonmonetary_credit_not_money` / `["iso4217_currency", "authoritative_monetary_evidence"]` | Forbidden (`0` monetary balance) |
| Host non-currency reward quantity (`POINTS`, `STARS`, `TOKENS`, `CREDITS`) | IM-08 | `unanchored_evidence` (or `nonfinancial_agreement_reference` if pure reference) | `null` | `import.noncurrency_reward_not_currency` / `["iso4217_currency", "authoritative_monetary_evidence"]` | Forbidden (never coerced to ISO currency) |
| Proven obligation after current plan changed | IM-09 | `proven_obligation` | Frozen historical `Money` | Exact historical `frozenPriceSnapshot` (`priceVersionRef`, `currency`, `taxPolicyVersion`) retained; `currentPlanSnapshot` ignored | No re-rating from current plan |
| Obligation with only `currentPlanSnapshot` or `documentDisplayPrice` | IM-09 | `unanchored_evidence` | `null` | `import.current_plan_cannot_anchor_history` / `["frozenPriceSnapshot"]` | Forbidden (no paid `PriceVersion` from current plan/document price) |
| Annual funding with 12 attached periodic grants | IM-13 | Single `proven_obligation` (if proven) + `nonfinancial_agreement_reference` for standalone grants | Annual funding only (`null` for grants) | Grants tracked as non-monetary references only | `0` obligations spawned for the 12 grants |
| Historical invoice with `originalIssuer` + `sourceNumber` | IM-16 | `proven_invoice_history` | Optional proven historical `Money` | Preserves `originalIssuer` & `sourceNumber`; `legalOwnershipConfirmed: false` stays historical evidence only | Never issues a second Billium legal invoice number |
| Exported source credential or unsanitized artifact | D71 | Artifact rejected before staging | N/A | `export.forbidden_source_credential` / `export.unsanitized_artifact` | Blocked fail-closed |

## 6. Hashed Local Runtime Reports vs External Activation Inputs

- **Verified local runtime evidence**:
  - Sanitized reference fixtures live under `fixtures/integration/import/reference-recurring/` and `fixtures/integration/import/reference-credits/`.
  - Evaluating reader output via `ImportPreviewEvaluator::evaluate()` emits a canonical SHA-256 hashed `external.import.preview.v1` report (`digest`) with `authoritativeCurrentBalance: false` and explicit missing-evidence classifications.
- **Explicitly separated external activation inputs**:
  - Production export manifests, source freeze certificates (`SourceFreezeCertificate`), owner-approved commercial price/source mappings, and target activation certificates (`ActivationCertificate`) are external owner-supplied activation inputs.
  - Local sanitized fixtures and local hashed preview reports verify reader correctness only and NEVER authorize production cutover or live activation.
