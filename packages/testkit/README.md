# Billium Testkit

Dev-only fixtures for Billium execution protocol tests. Runtime packages do not depend on this package; the monorepo installs it through Composer `require-dev`.

The package provides deterministic provider capability modes, independently durable provider/receiver/authority journals, dispatch ticket and egress controls, an independent effect oracle, and child-process/crash barriers. Journal state lives outside the Billium database so a test can compare it across database rollback or dump/restore.

All provider and authority guarantees in these fixtures are explicitly simulated inputs. A passing fixture test does not qualify a live provider, production host fencing or a physical PITR procedure. Money state in Billium remains owned by the PHP runtime; the fixture oracle does not call the production reducer to calculate expected totals.

Run the testkit in the project PHP 8.4+ toolchain with the configured, isolated PostgreSQL test database and normal preflight/lease guards:

```sh
vendor/bin/pest packages/testkit/tests
```

The suite includes real child processes, independent PostgreSQL backends and process termination. Reuse the project stand helper with a dedicated lane when running alongside other work.
