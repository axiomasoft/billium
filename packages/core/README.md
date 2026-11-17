# axiomasoft/billium-core

Billium Laravel 13 core runtime containing the deterministic financial admission barrier, platform outbox/inbox/session facilities, and ledger module.

## Architecture

- Built on `axiomasoft/packman`, `axiomasoft/packman-plugins`, and `axiomasoft/packman-schema`.
- Composes `PlatformPlugin` and `LedgerPlugin` via `ExtensionPlugin` and `With*` facet contracts.
- Provides `BilliumOptions` closed-schema configuration, `BilliumSchemaSetProvider`, and `BilliumCompatibilityDoctorCheck`.
