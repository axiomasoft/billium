# Server operations

The deployment owner selects the [server composition](compositions.md), primary PostgreSQL connection, core migrations and host ports. The `ledger-only` and server `embedded-commerz` profiles use host financial-session admission for ledger writes and disallow execution, collection and `WriterAdmission`. The `remote-billium` profile requires execution and host outgoing authority. The `standalone-billium` profile is embedded. (D18, D55, D61, D62; owners deployment and core.)

## Probes and diagnostics

`GET /billium/live` checks the HTTP process. `GET /billium/ready` returns 200 when the worker passes the probe and 503 otherwise. Both responses are empty and use `Cache-Control: no-store`. During recovery, route new financial work away from the unready workers and keep authenticated history lookup and raw custody reachable. (D54, D59, D62; owners server and deployment.) [HTTP API](../contracts/server-api-v1.md)

Set `billium.installation_ref` to the worker's deployment installation. Run the doctor on the target attachment with the worker's trusted host context. The table uses suffixes under `billium.server.`. (D59, D62; owners server and deployment.)

```bash
php artisan packman:doctor --prefix=billium.server --json
php artisan billium:server:metrics
```

| Finding | Operator action and source |
| --- | --- |
| `readiness` | This worker passed the probe. Core checks the exact operation scope, driver and authority again before admission and dispatch. (D57, D62; owners platform and execution.) |
| `composition_unavailable`, `modules_incompatible`, `modules_not_accepting` | Check the enabled composition, migrations, event feed signing and module lifecycle. (D55, D61; owners core and deployment.) |
| `auth_unavailable`, `limits_invalid` | Check host authentication, installation/seller context, eligibility, authorization and compiled HTTP limits. (D23, D59; owners host and server.) |
| `database_unavailable`, `database_incompatible`, `database_role_invalid` | Check PostgreSQL 16+, the writable primary and its runtime role. Apply migrations with the separate owner role. (D60; owners platform and deployment.) |
| `session_quarantined` | Check the process token cache, deployment and attachment generations, activation evidence and session state. (D54, D62; owners host authority and platform.) |
| `execution_ports_unavailable`, `outgoing_disabled`, `execution_unqualified` | Check the execution authority, egress and driver bindings, outgoing toggle, dossier selectors and host `ExecutionReadiness`. Confirm exact frozen driver, merchant revision, capability and action coverage. (D57, D59, D61; owners deployment and execution.) |

The metrics command samples the primary and emits unlabeled aggregate counts and ages. It reports feed gaps, parked custody, conflicts, draining modules, outbox lag and unmatched refund charge totals. For execution it also reports accepted-unknown count and oldest operation age, unknown attempts, expired attempt leases and namespaces in recovery. Use the current worker's quarantine gauge and sample execution signals only where the module is enabled. Collect authoritative drain blocker counts from core transition results. A failed sample returns `billium.server.metrics_unavailable`. (D55, D57, D59, D63; owners server, platform, ledger and execution.)

Count `billium.server.receipt_replayed`, `billium.server.command_conflict` and `billium.server.observation_conflict` from the server logs for HTTP replay and conflict rates. These events carry fixed names without request data. Keep references and digests in controlled receipt and custody audit stores. (D31, D58, D59; owners host, server and platform.)

## Restore, clone and lost responses

1. On transfer, restore, clone or loss of authority continuity, close old admission and establish an external attachment barrier. Routine FPM or queue restarts get new process tokens for the same OwnerSession. Derive expected owner, epoch and generation from the trusted host token. (D54, D62; owners deployment and host authority.)
2. Drain or isolate old senders and their external egress, then retain a `RetirementProof` outside the database. Seal and release close the old session. Open the replacement in recovery. Use `max(commit_no)` only as a diagnostic. In-place rollback under live workers without an external attachment barrier is unsupported. (D54, D62; owners host authority and platform.)
3. Reconcile retained history, raw custody, provider/source inventory, unresolved effects and feed gaps for every affected namespace. Keep original executor and refund bindings. After a lost HTTP reply, look up the existing operation or reuse the same prepared bytes, key and digest where replay is permitted. Determine the economic outcome from authorized lookup and business receipts. (D31, D54, D57, D58, D63; owners execution, platform and original authority.)
4. Verify the chosen backup lineage, retirement boundary and reconciliation. Obtain a current `ActivationCertificate` and issue the separately privileged activation operation. Verify that the old and copied processes cannot dispatch. (D54, D59, D62; owners host authority, deployment and platform.)

Run the drill on the disposable PostgreSQL test stand. It covers real backup restore and a stale clone racing the original, worker attachment readiness, authenticated history lookup, and HTTP-04 over loopback with an injected lost reply. The authority and provider are fixtures. The default report is `build/server-readiness/drill.json`. Pass `--report` a destination resolved by the workspace evidence resolver and retain the report with its referenced sibling logs and transcript. (D54, D58, D59, D62; owners PLAN1.P8.8 and deployment.)

```bash
bash scripts/drills/server-restore.sh
```

## Activation gate

The deployment owner supplies the following approved evidence before enabling outgoing execution. All entries await production inputs. (D54, D57, D59, D62; owner deployment.)

| Gate | Deployment evidence |
| --- | --- |
| Named owner | Operator, on-call route, and authority for seal, recovery and activation. |
| SLO | Service and recovery targets, alert budgets, RPO/RTO and monitored consumers. |
| Retention and backups | Retention floors, custody and audit policy, backup lineage and measured deployment restore, aligned with provider lookup and replay windows. |
| Authority | External retirement and egress isolation, reconciled effects and gaps, and a current lineage certificate. |
| Drivers | Qualification of the selected PaymentDrivers release and frozen merchant/capability bindings, with scoped provider-live evidence. |
| Activation | Explicit outgoing configuration and privileged activation with retained evidence. |

The [profile evidence manifest](../../artifacts/server-readiness.json) distinguishes PostgreSQL runtime, loopback, package archives, doubles and provider-live qualification. The Dart SDK item is terminal as accepted non-extraction with no named consumer. PHP/Dart transport and N/N−1 archive checks remain required. Independent phase acceptance follows the coordinator's frozen candidate and gate. (D59, D61, D64, D66; owners PLAN1.P8.6–P8.9.)
