# billium_contracts

Dart client of the Billium contracts v1 (spec revision `2026-10`): money, canonical digest, envelopes and problem
codes. Dart builds commands and reads outcomes; it never writes money state (REQ-OWN-18). The package shares nothing
with the PHP package `axiomasoft/billium-contracts` except `spec/revisions/2026-10/` and `fixtures/`.
`publish_to: none`; Dart VM only (dart2js is out of scope, REQ-CON-37).

## Public API

Import `package:billium_contracts/billium_contracts.dart`; everything under `lib/src/` is internal.

| Type | Role |
|:--|:--|
| `Codec` | `decode(json): Envelope`, `encode(Envelope)`, `canonicalizeJson(json)`, `canonicalize(value)`, `digestJson(json)`, `digest(value, member:)`, `digestOf(Envelope)`, `decodeMoney(json)`, `decodeProblem(json)` |
| `MoneyMath` | `add`, `allocate` (largest remainder), `round` (`halfEven`, `halfUp`) on `BigInt` minor units |
| `Money`, `Currency` | `BigInt minor` inside int64 and a currency with its scale; `Money.parse`, `fromWire`, `toWire`, `amount` |
| `ExecutePayment`, `PaymentOutcome`, … | one class per envelope of the revision (generated), built by `Codec.decode` |
| `ProblemCode`, `RoundingMode` | the code registry with category, retryable, sameKey and receipt policy; the rounding modes |
| `ProblemException` | every refusal: a `ProblemCode` and a detail |

`Codec.decode` checks in order: JSON syntax, `schema`, fields, amounts. A `digest` carried by a command must equal the
digest of its canonical form. Amounts are strings: a JSON number on an amount is `billium.money.scale_invalid`, a
`double` appears nowhere in `lib/`, and `int` carries only `revision` and `routingEpoch` (inside `[0, 2^53 - 1]`).
SHA-256 comes from `package:crypto`, the only runtime dependency; RFC 8785 strings are serialized by `lib/src/jcs.dart`
because `jsonEncode` escapes differently.

## Generated files

`lib/src/generated/*.g.dart` (currency table, problem codes, schema bundle, envelope classes) come from the spec
revision and carry `// dart format off`. Do not edit them:

```bash
docker compose run --rm toolchain python3 scripts/spec/export-dart.py          # rewrite
docker compose run --rm toolchain python3 scripts/spec/export-dart.py --check  # layer derived
```

## Checks

```bash
docker compose run --rm toolchain bash scripts/ci.sh --layer dart               # pub get, format, analyze --fatal-infos, test
docker compose run --rm toolchain python3 conformance/runner/run.py --adapter dart   # fixtures corpus, empty baseline
```

The conformance adapter is `conformance/adapters/dart/bin/adapter.dart`, a separate package with a path dependency on
this one; it imports the public library only.
