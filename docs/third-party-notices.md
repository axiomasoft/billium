# Third-Party Notices and License Policy

This document summarizes the third-party dependency inventory and license policy for Billium (PLAN1.P10.6, D71/D72).

## 1. License Policy

- **First-party packages** (`axiomasoft/billium-contracts`, `axiomasoft/billium-core`, `axiomasoft/billium-server`, `axiomasoft/billium-testkit`): `proprietary` (AxiomaSoft).
- **Permitted third-party licenses:** `MIT`, `BSD-2-Clause`, `BSD-3-Clause`, `Apache-2.0`, `ISC`, `0BSD`, `MPL-2.0`, `Unlicense`, `CC0-1.0`, `PSF-2.0`, `Zlib`.
- **Disallowed copyleft-only licenses:** `GPL-*` and `AGPL-*` without a permissive dual-license option are forbidden unless explicitly approved and waived by legal/release owners.

## 2. PHP Dependencies (`composer.lock`)

All locked PHP runtime and development dependencies in `composer.lock` are either first-party (`proprietary`) or licensed under permissive open-source licenses (`MIT`, `BSD-3-Clause`, `Apache-2.0`).

### Dual-Licensed Packages Resolved Under Permissive Terms

The following packages declare multiple licenses including GPL variants and are used strictly under their permissive **`BSD-3-Clause`** license:

| Package | Declared Licenses | Selected License |
| :--- | :--- | :--- |
| `nette/schema` | `BSD-3-Clause`, `GPL-2.0-only`, `GPL-3.0-only` | `BSD-3-Clause` |
| `nette/utils` | `BSD-3-Clause`, `GPL-2.0-only`, `GPL-3.0-only` | `BSD-3-Clause` |
| `nette/neon` (dev) | `BSD-3-Clause`, `GPL-2.0-only`, `GPL-3.0-only` | `BSD-3-Clause` |

## 3. Dart Contract Package (`packages/contracts-dart/pubspec.lock`)

`packages/contracts-dart` is a pure codec/contract package with a single direct runtime dependency (`crypto`, `BSD-3-Clause`) plus standard Dart test/analyzer dev dependencies locked in `packages/contracts-dart/pubspec.lock`.

## 4. Automated Supply-Chain Verification

Run the automated license and release-hold gate:

```bash
docker compose run --rm -u 0:0 toolchain bash scripts/quality/release-supply-chain.sh
```

The script inspects `composer.lock` and `packages/contracts-dart/pubspec.lock`, fails nonzero on disallowed copyleft-only or unknown licenses, and fails nonzero if a selected release profile has unresolved holds.
