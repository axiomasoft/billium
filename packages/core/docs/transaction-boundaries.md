# Transaction boundaries

`OperationRunner` owns one physical PostgreSQL root transaction. A participant must use
the current `TransactionContext` and its admitted module closure. Contexts are bound to
the PDO and PostgreSQL top-level transaction ID; rolling back and starting another
transaction on the same connection cannot revive them. Balanced nested savepoints are
permitted, but the body must return at its original depth. An imbalance fails the whole
attempt, including its receipt and outbox. Callbacks run after physical root commit.

For `recovery_only` and `retired`, bind
`Billium\Core\Contracts\Modules\ModuleTransitionEvidence` to an owning-module reader
(or a dispatcher to those readers). Platform invokes it after taking every affected
module row exclusively, including owners changed by a cascade. The reader must obtain
or validate all facts on the context's current transaction, installation and generation.
Writers of those facts must participate in the module admission barrier. Return `null`
when the evidence is incomplete, stale or unverifiable. An unbound reader refuses the
transition with `evidence_unavailable`; request counts and `evidenceRef` alone cannot
authorize it. Delivery, recovery and other owning modules supply their implementations
as their persistence is implemented. Committed transition receipt replay needs no new
evidence read.

With separate migration-owner and runtime roles, `billium.connection = null` selects
the host's default runtime connection. Configure that default before application boot.
Platform retains its name before `migrate --database=billium_owner` temporarily changes
Laravel's default, so migrations grant privileges to the runtime role.

`LedgerParticipants` declares all six typed participants, including invoice issuance
with a `legalDocumentRef`, credit-note issuance and obligation adjustment. The command
DTOs carry inputs; owning validation, mutation implementations and their economic
invariants remain P3.8 work. No participant exposes SQL or a database connection.

Stored receipts preserve nested JSON object/list shapes, including `{}` and objects
whose keys are numeric, across PostgreSQL JSONB storage and replay.

Targeted proofs, with the configured PostgreSQL owner/runtime test roles:

```sh
php8.4 vendor/bin/pest packages/core/tests/Feature/ParticipantContextIsolationTest.php
php8.4 vendor/bin/pest packages/core/modules/platform/tests/Feature/OperationRunnerTest.php
php8.4 vendor/bin/pest packages/core/modules/platform/tests/Feature/IdempotencyStoreTest.php
php8.4 vendor/bin/pest packages/core/modules/platform/tests/Feature/RuntimeGrantsTest.php
php8.4 vendor/bin/pest packages/core/modules/platform/tests/Concurrency/ModuleAdmissionTransitionTest.php
```
