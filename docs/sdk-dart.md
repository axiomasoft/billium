# Dart SDK extraction

**P8.7 disposition: accepted non-extraction (D61).** The execution input and
workspace contain no Dart application named by the Billium plan owner as a
consumer of the server API. The owner-approved D61 branch records P8.7 as
**skipped**. The SDK package, public API snapshot, SDK conformance adapter, and
consumer archive smoke apply to the extraction branch.

## G-SDK-DART evidence

| Gate input | Evidence for P8.7 |
| --- | --- |
| Named consumer (D17, D55, D61) | No production consumer or consumer example was supplied. The available Dart client is the [P8.6 conformance harness](../conformance/clients/dart/pubspec.yaml). |
| Consumer VM target (P8.7 SDK contract) | No application VM target was supplied. |
| Consumer auth contract (P8.7 SDK contract) | No consumer identity, credential contract, or required authorization scopes were supplied. |
| Package availability (D9, D17, D61) | `billium_contracts` is available in the [repository](../packages/contracts-dart/pubspec.yaml) and as the pinned prior archive listed in the [P8.6 matrix](../conformance/compatibility/matrix.json). `billium_sdk` remains gated. |
| Invariant (D58, REQ-OWN-18, REQ-CON-26, REQ-CON-37) | Dart issues online commands and queries. Financial state and execution stay with the authoritative PHP writer. Recovery looks up the original operation, and any replay preserves its key and digest. [Billium requirements](requirements.md) |

## P8.6 evidence retained by D61

- `transport-contract` covers shared PHP/Dart wire behavior using the test
  clients and HTTP corpus. [HTTP runner](../conformance/http/run.py)
- `compatibility-archive` covers the current clients and the hash-pinned P2
  generation from spec revision `2026-10`. [Matrix](../conformance/compatibility/matrix.json),
  [archive provenance](../conformance/compatibility/PROVENANCE.md)
- The archive install smoke exercises the PHP and Dart **test consumers**.
  [P8.6 consumer smoke](../scripts/smoke/server-consumers.sh)

## Acceptance by branch

- With extraction granted, V871 runs the HTTP corpus through the production
  `dart-sdk` adapter. V872 installs the SDK archive and runs the named
  consumer on its Dart VM target (D17, D59, D61).
- With accepted non-extraction, the coordinator records a lifecycle skip
  for P8.7 under D61. The P8.6 evidence retains its transport and archive
  boundaries (D59, D61).
