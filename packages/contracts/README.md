# axiomasoft/billium-contracts

PHP side of the Billium contracts v1 (spec revision `2026-10`): money, canonical digest, envelopes and problem
codes. No persistence, no provider SDK, no Laravel dependency (REQ-CON-01). PHP is the only money writer; the Dart
package `billium_contracts` is a client of the same contract and shares nothing with this package except
`spec/revisions/2026-10/` and `fixtures/`.

## Public API

Everything outside `Internal\` carries `#[Api]`; the package reads nothing outside itself after installation.

| Type | Role |
|:--|:--|
| `Contracts\Codec` | `decode(json): Envelope`, `encode(Envelope)`, `canonicalize(json\|value)`, `digest(json\|value, member)`, `digestOf(Envelope)`, `decodeMoney(json)`, `decodeProblem(json)` |
| `Contracts\MoneyMath`, `MoneyMathFactory::create()` | `add`, `allocate` (largest remainder), `round` (`HALF_EVEN`, `HALF_UP`) on integer minor units |
| `Data\Money`, `Data\Currency` | int64 minor units and a currency with its scale; `Money::parse`, `fromWire`, `toWire`, `amount` |
| `Data\Envelopes\*` | one readonly class per envelope of the revision (generated), built by `Codec::decode` |
| `Enums\ProblemCode`, `Enums\RoundingMode` | the code registry with category, retryable, sameKey and receipt policy; the rounding modes |
| `Exceptions\ProblemException` | every refusal: a `ProblemCode` and a detail |
| `Attributes\Api`, `Spi`, `SpiKind` | API markers |

`Codec::decode` checks in order: JSON syntax, `schema`, fields, amounts. A `digest` carried by a command must equal the
digest of its canonical form. Amounts are strings; a JSON number on an amount is `billium.money.scale_invalid`, and no
public signature or property has a float.

Money arithmetic runs on `brick/money` and `brick/math` (D44): the money vectors pass 149 of 149 with a float-free
allocation path; `moneyphp/money` failed three allocation vectors and distributes the remainder through floats.

## Generated files

`resources/` (currency table, schema bundle, envelope registry), `src/Enums/ProblemCode.php`,
`src/Contracts/SpecRevision.php` and `src/Data/Envelopes/*.php` come from the spec revision. Do not edit them:

```bash
docker compose run --rm toolchain python3 scripts/spec/export-php.py          # rewrite
docker compose run --rm toolchain python3 scripts/spec/export-php.py --check  # layer derived
```

## Checks

```bash
docker compose run --rm toolchain bash scripts/ci.sh --layer php              # composer validate, Pint, PHPStan, Rector, Pest
docker compose run --rm toolchain python3 conformance/runner/run.py --adapter php   # fixtures corpus, empty baseline
```

The conformance adapter is `conformance/adapters/php/bin/adapter.php`; it uses the public API only.
