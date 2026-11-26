# Registered usage attribution and correction policies

`usage.metric.v1` retains its envelope identity. Its policy objects have an explicit, additive
version registration. Selecting policy version 2 requires a new immutable `metricVersion` and
canonical metric digest; it does not reinterpret retained version 1 definitions or facts. PHP is the
only runtime writer. Dart validates and roundtrips these wire inputs only.

| Policy | Version | Required host inputs | Admission semantics |
| --- | --- | --- | --- |
| `immutable_host_snapshot` | 1 | `id`, `version` | Resolve the fact's historical host mapping and retain its full attribution, mapping validity and original commercial period. |
| `immutable_host_snapshot` | 2 | `id`, `version`, `mappingRef`, `attribution` | In addition to every version 1 host authorization and interval check, require the resolved mapping and full fact attribution to equal the metric's explicit immutable pins. |
| `replace_same_fact_revision` | 1 | `id`, `version` | Accept a contiguous full-replacement revision with the exact predecessor digest and immutable original attribution; no policy revision ceiling. |
| `replace_same_fact_revision` | 2 | `id`, `version`, `maxRevision` | Preserve the version 1 chain and reject a fact whose revision exceeds the explicit positive ceiling before writing its receipt, source frontier or financial state. |

The pinned `attribution` uses the existing complete host snapshot contract: customer, billing
account, subject, component, agreement, agreement revision, `host_snapshot/1` attribution version
and effective time. A pin is a restriction on an approved host lookup; it cannot grant authority,
choose a payer on lookup failure, bypass source authentication or replace original-period validity.
Version 1 may accept different host-approved mappings under the same metric; version 2 deliberately
binds that metric definition to one declared mapping. Replaying an admitted fact uses its durable
receipt and retained original attribution after host mappings change.

`maxRevision` is an integer from 1 through 9007199254740991, supplied explicitly for the new metric.
For example, 1 admits the original fact and refuses all corrections; 3 permits revisions 1–3 only.
This bounds source correction admission; it is not a product quota, a history retention rule, a
financial delta or permission to skip predecessors. Exceeding the ceiling never changes previously
admitted meaning. No ceiling is inferred for version 1 and no history is erased.

Both registrations are closed objects. Missing pins or ceiling, zero/negative/noninteger ceilings,
extra fields and unregistered policy versions refuse. Existing version 1 canonical bytes/digests
remain unchanged. Selecting prices, tax, payer mappings, periods and the correction ceiling remains
with the host; fixture values are test inputs and have no production default.

The extension evidence includes independent PHP/Dart codec vectors in
`fixtures/vectors/usage/contracts/metric-policy2-*.json`, runtime admission and boundary cases in
`UsagePolicyVersionTest`, and `P4HistoricalMetricRatingTest`: accept the changed metric version,
admit its fact, then reproduce the original metric's aggregate, rated digest and exact booked money
without creating another obligation. Unknown version 3 controls retain the explicit refusal path.
The final phase report binds these assertions to actual integrated check results and the reviewed
candidate; the presence of these files alone is not phase approval.
