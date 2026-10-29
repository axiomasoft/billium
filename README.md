# Billium

Billing for the software seller of the Axioma ecosystem, implemented in **PHP**.
**Dart** is the contracts client and SDK: it builds commands and reads outcomes, but never writes
money state.

The repository contains the executable plan `plans/2026.10.14-№1-BILLIUM-V1`, the P1 design,
the contracts spec revision [`spec/revisions/2026-10`](spec/revisions/2026-10/README.md) (draft),
language-neutral [`fixtures/`](fixtures/README.md), the `conformance/` runner with the JCS oracle,
and the `billium-contracts` packages for PHP and Dart. Core implements the P3 platform and ledger:
guarded PostgreSQL routines, financial-session admission, receipts, observations, outbox and feed (D60).
Live PSP execution and legal numbering belong to later modules (D55/D59).

- [Requirements and traceability](docs/requirements.md)
- [Target architecture](docs/architecture/target-architecture.md) and
  [data model](docs/architecture/data-model.md)
- [Lock order, runtime privileges and invariant proofs](docs/architecture/lock-model.md) (D27/D60)
- [billium-contracts v1](docs/contracts/billium-contracts-v1.md)
- [Acceptance catalog](docs/acceptance-catalog.md)
- [PHP contracts package](packages/contracts/README.md): `Codec`, `Money`, `MoneyMath`, envelopes
- [Dart contracts package](packages/contracts-dart/README.md): the same contract for the client, `BigInt` money

Stack: PHP 8.4+, Laravel 13, PostgreSQL 16+; packages `axiomasoft/billium-*` in `packages/<name>/`
(embedded Laravel package and server profile from one codebase).

## Development

All checks run in the `toolchain` image (PHP 8.4, Composer, Dart SDK, Python tooling); the host needs only Docker.

```bash
docker compose build toolchain
docker compose run --rm toolchain composer install --no-interaction
docker compose up -d postgres
docker compose run --rm toolchain php scripts/db/preflight.php
docker compose run --rm toolchain vendor/bin/pest --group ACC-RST-10
docker compose run --rm toolchain bash scripts/ci.sh                 # full gate
docker compose run --rm toolchain bash scripts/ci.sh --layer derived # one layer
docker compose run --rm toolchain bash scripts/ci.sh --list          # layers and subjects
docker compose run --rm toolchain bash scripts/smoke/consumer-install.sh
```

Gate layers, in order: `lint` (spec documents, schemas, vectors, migration/role rules and P3 acceptance groups), `derived` (freshness of generated files,
identities, type-map), `php` (Pint, PHPStan max with `NoFloatTypeRule`, Rector, Pest, install smoke), `dart`
(format, analyze, test, `no-double`, install smoke), `conformance` (shared vectors and golden through both
adapters against the baseline). Details: [development environment](docs/development-environment.md).

## Agent environment

The environment follows AgenTools: swissknifeman plugins use the **connect**
channel, mAInd provides project-scoped memory and ecosystem links, and Serena
provides code navigation for PHP and Dart. Claude Code, Codex, Cursor, and Grok
are enabled in `.swissknife.json`.

See [development environment](docs/development-environment.md) for setup and
verification commands.

See [planning and design checks](docs/planning.md) for the current P1 plan and its validation commands.

Platform outbox delivery, feed subscriptions and retention setup: [operations guide](docs/operations/outbox-feed.md) (D52/D60/D63).

Authenticated ledger queries, receipt recovery and ledger bootstrap streams: [query guide](docs/contracts/ledger-queries.md) (REQ-CON-14, D35, D60).

Host Laravel commands: `billium:installation:init`, `billium:outbox:relay`, `billium:outbox:prune`,
`billium:ledger:drain-parked`, `billium:ledger:reconcile-refunds`. Prune requires an explicitly configured
RET-01 retention floor; init does not activate a financial session (D54/D60/D62).
See the [development environment](docs/development-environment.md) for setup and proof boundaries.
