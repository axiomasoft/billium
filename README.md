# Billium

Billing for the software seller of the Axioma ecosystem, implemented in **PHP**.
**Dart** is the contracts client and SDK: it builds commands and reads outcomes, but never writes
money state.

Plans live exclusively in the SwissKnifeman workspace `axioma`, under
`projects/billium/plans/2026.10.14-№1-BILLIUM-V1/`; see [planning](docs/planning.md).
The repository contains the P1 design, the contracts spec revision
[`spec/revisions/2026-10`](spec/revisions/2026-10/README.md) (draft),
language-neutral [`fixtures/`](fixtures/README.md), the `conformance/` runner with the JCS oracle,
and the `billium-contracts` packages for PHP and Dart. Domain code (core, persistence) starts in P3.

- [Requirements and traceability](docs/requirements.md)
- [Target architecture](docs/architecture/target-architecture.md) and
  [data model](docs/architecture/data-model.md)
- [billium-contracts v1](docs/contracts/billium-contracts-v1.md)
- [Acceptance catalog](docs/acceptance-catalog.md)
- [PHP contracts package](packages/contracts/README.md): `Codec`, `Money`, `MoneyMath`, envelopes
- [Dart contracts package](packages/contracts-dart/README.md): the same contract for the client, `BigInt` money

Stack: PHP 8.4+, Laravel 13, PostgreSQL 16+; packages `axiomasoft/billium-*` in `packages/<name>/`
(embedded Laravel package and server profile from one codebase).

## Development

All checks run in the `toolchain` image (PHP 8.4, Composer, Dart SDK, Python tooling).
The host needs Docker and the `axioma` workspace checkout. Compose mounts the workspace read-only;
set `SKM_WORKSPACES` to its parent directory when it is outside the default SwissKnifeman machine home.

```bash
docker compose build toolchain
docker compose run --rm toolchain composer install --no-interaction
docker compose run --rm toolchain bash scripts/ci.sh                 # full gate
docker compose run --rm toolchain bash scripts/ci.sh --layer derived # one layer
docker compose run --rm toolchain bash scripts/ci.sh --list          # layers and subjects
docker compose run --rm toolchain bash scripts/smoke/consumer-install.sh
```

Gate layers, in order: `lint` (spec documents, schemas, vectors), `derived` (freshness of generated files,
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
