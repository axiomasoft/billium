# Deployment Profile and Provider Qualification Manifest

This runbook defines the machine-validated deployment activation manifest (`spec/deployment-profile.schema.json`) and its CLI validator (`scripts/quality/deployment-profile.py`) for Billium (PLAN1.P10.7, D71/D72).

## 1. Purpose and Activation Boundary

Every Billium deployment declares an explicit deployment profile manifest before activation. The manifest separates:

- **Lightweight `contracts-only` profile** (`profileType: "contracts-only"`, `activationMode: "contracts-only"`, `modules.executionEnabled: false`, `qualification.status: "not_applicable"`): requires no seller binding, payment driver, retention policy, SLO, or provider qualification evidence. It cannot be promoted to `--require-live`.
- **Runtime profiles** (`embedded`, `standalone`, `remote`): require explicit `seller`, `authority`, `mandateAndFiscal`, `retention`, `slo`, and `evidenceRefs` sections, plus `driver` whenever execution is enabled or active.
- **Live activation boundary** (`activationMode: "live"` or `environment: "production"` with `modules.executionEnabled: true`): enforces strict fail-closed qualification rules before any live monetary egress is permitted.

## 2. Live Provider Qualification Rules

A deployment manifest is **blocked** from live activation unless all of the following hold:

1. **Provider-verified qualification**: `qualification.status == "qualified"` and `qualification.mode == "provider_verified"`. Simulated test-double evidence (`mode == "simulated"`) never qualifies live execution.
2. **Zero unresolved gaps**: `qualification.unresolvedGaps` is empty, and `manifestRef`, `checkedAt`, `officialSources`, and `liveEvidenceRefs` are populated.
3. **Historical lookup and refund support**: `driver.historicalLookupSupported` and `driver.historicalRefundSupported` are both `true`, and `driver.capabilities` includes lookup (`lookup_by_provider_id` or `lookup_by_reference`), `webhook_authenticity`, and `webhook_event_identity`.
4. **Authority and egress quiesce**: `authority.owner` is set, `authority.writerAuthorityKind != "none"`, `authority.egressQuiesceSupported == true`, `authority.egressQuiesceRunbookRef` is non-empty, and `authority.fencingEpochRequired == true`.
5. **Mandate and fiscal completeness**: enabling `recurringMandateEnabled` requires `mandatePolicyRef` and driver capability `recurring_consent`; any non-`none` `fiscalMode` requires `fiscalProfileRef`.
6. **Retention and SLO/RPO/RTO**: `retention.legalHoldSupported == true` with positive retention floors and explicit SLO (`availabilityTargetPct`, `p95LatencyMs`, `rpoSeconds`, `rtoSeconds`, `escalationContact`).
7. **Evidence references and hygiene**: all six `evidenceRefs` (`releaseEvidenceRef`, `retentionPolicyRef`, `restoreDrillRef`, `securityGateRef`, `supplyChainRef`, `qualificationEvidenceRef`) are present, and no inline secret material appears anywhere in the manifest.

At runtime, `ExecutionReadinessGuard` additionally fails closed (`billium.recovery.required`) if `billium.execution.outgoing_enabled` is `false` or `release_evidence_ref` / `retention_policy_ref` is missing.

## 3. Verification Commands

```bash
# Validate a deployment manifest JSON (exit 0 on valid, nonzero on invalid)
python3 scripts/quality/deployment-profile.py path/to/manifest.json --json

# Enforce live activation qualification
python3 scripts/quality/deployment-profile.py path/to/manifest.json --require-live --json

# P10.7 automated test suite (no live charges or external network calls)
docker compose run --rm -u 0:0 toolchain vendor/bin/pest --filter=DeploymentProfile
```
