# Billium

Billing for the software seller of the Axioma ecosystem, implemented in **PHP**.
**Dart** is the contracts client and SDK: it builds commands and reads outcomes, but never writes
money state.

The repository contains the development environment configuration, the executable plan
`plans/2026.10.14-№1-BILLIUM-V1`, and the P1 design. Production code starts in P2.

- [Requirements and traceability](docs/requirements.md)
- [Target architecture](docs/architecture/target-architecture.md) and
  [data model](docs/architecture/data-model.md)
- [billium-contracts v1](docs/contracts/billium-contracts-v1.md)
- [Acceptance catalog](docs/acceptance-catalog.md)
- [PHP contracts package](packages/contracts/README.md): `Codec`, `Money`, `MoneyMath`, envelopes

Stack: PHP 8.4+, Laravel 13, PostgreSQL 16+; packages `axiomasoft/billium-*` in `packages/<name>/`
(embedded Laravel package and server profile from one codebase).

## Agent environment

The environment follows AgenTools: swissknifeman plugins use the **connect**
channel, mAInd provides project-scoped memory and ecosystem links, and Serena
provides code navigation for PHP and Dart. Claude Code, Codex, Cursor, and Grok
are enabled in `.swissknife.json`.

See [development environment](docs/development-environment.md) for setup and
verification commands.

See [planning and design checks](docs/planning.md) for the current P1 plan and its validation commands.
