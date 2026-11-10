# PaymentDrivers contract boundary

Owners: execution for provider contracts and evidence; contracts for shared wire values.
Normative sources are [BC-CAP-01–06](../../spec/revisions/2026-10/07-provider-capabilities.md),
D26, D31, D54, D57, D59, D62 and D63.

P5.1 supplies the PHP `PaymentDrivers` and `WriterAdmission` interfaces, immutable normalized
capability/configuration DTOs, a pure `CapabilityGate`, and generated PHP/Dart wire types.
It does not implement a journal, provider adapter, dispatcher, authority service or lifecycle executor.
The optional execution service provider registers only the capability gate, leaves provider/authority
ports unbound and creates no tables, routes or workers. The host must bind those ports explicitly.

Call `CapabilityGate::check` with the frozen snapshot, required workflow and pinned qualification
before entering operation/attempt/reservation admission. A non-null `UnsupportedCapability` is a
deterministic refusal with `billium.capability.unsupported`, receipt `none`, `retryable=false` and
`sameKey=true`. A null result establishes capability eligibility only. Future mutation paths still
require OperationRunner, the complete ordered LockSet and authenticated WriterAdmission; network
calls belong outside transactions. Refund, capture, void and recovery retain the root binding.

`payment.execute.v1` continues to mean sale. New `payment.authorize.v1` selects authorization,
while `payment.capture.v1` and `payment.void_authorization.v1` identify separate child effects.
Admission uses the existing `CommandAccepted` receipt; normalized child outcomes use the unchanged
P3.4 `payment.effect.outcome.v1` and negotiated v2 catalog. `payment.cancel.v1` stays local.
No transport acknowledgement becomes a receipt or provider outcome.

`payment.driver_lookup.v1` explicitly distinguishes found, unavailable, ambiguous and certified
absence. `CertifiedAbsent` checks account/environment/selector identity, the full old-dispatch
interval and the pinned complete-lookup capability. Its fence reference and provider horizon must
still be authenticated by the recovery owner against external evidence. A DTO cannot fence a paused
sender. Partial lookup, elapsed provider-key TTL, timeout or a generic 404 never certifies absence.

Saved-method, recurring-consent, settlement, inventory, dispute and fiscal support each have an
explicit disposition. Supporting a payment family such as SBP does not imply supporting its refund,
lookup or recurring workflows. Ledger read shapes already distinguish observed actual evidence from
last consistently applied totals, hold/exposure and spendable values; this boundary does not apply
provider evidence to ledger balances.

## Qualification evidence

[capability-contract-manifest.json](capability-contract-manifest.json) is a **simulated**, dated,
version-pinned test manifest. Its example.invalid URL identifies fixture provenance; it is not an
official PSP source or a production qualification claim. It matches the independent shared fixtures
`simulated-sbp-a` and `simulated-qualification`. `simulated-sbp-b` intentionally has different
lookup/refund support. Qualification digest/version mismatch, missing evidence or unresolved gaps
refuse admission. Neither simulated nor sandbox qualification enables production eligibility.

RU adapter selection/qualification is deferred to the named provider integration gate (COV-532,
D59). YooKassa, CloudPayments and named-bank SBP readiness are **[UNVERIFIED]** in this item.
No SDK, production credential, live sandbox evidence, callback-signature proof or account-rotation
proof is supplied here. The future adapter owner must replace simulated provenance with official
versioned URLs and checked dates, reproducible provider evidence, exact dedupe/lookup limits and
historical account/credential continuity permissions before qualifying that adapter.

The shared callback capability controls cover authenticity and stable event identity. Independent
PHP/Dart tests also verify a simulated HMAC-SHA256 signature over exact callback bytes and reject
wrong-signature and altered-account-body controls. The test-only scheme does not verify a real
provider signature. A provider adapter returns `VerifiedCallback` only after verification,
or `RejectedCallback` for authentication refusal. Real cryptographic/provider qualification remains
the adapter gate; Dart contains only wire validation and encoding.

## Focused proofs

- `CapabilityContractTest`: every capability requirement, capture modes, pre-admission refusal
  with instrumented operation/attempt/reservation/provider counters, qualification pinning,
  production rejection of simulated/sandbox evidence, and incomplete-lookup recovery.
- `EffectEnvelopeCompatibilityTest`: shared positive/negative vectors, preserved pilot decode/digest
  behavior, strict historical enums and the pinned P3.4 payload bytes.
- `ExecutionModuleBootTest`: optional contract-only composition leaves mutation authority unbound.
- Dart `payment_capability_codec_test.dart`: independent decode, canonical encode/digest and error
  controls from the same vectors. No Dart financial runtime is introduced.

The coordinator owns the plan artifact copy and acceptance V1–V3. Product evidence lives here so
the executor does not mutate plan storage.
