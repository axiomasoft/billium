# Server delivery and composition

`axiomasoft/billium-server` exposes the same PHP ledger and execution APIs used in embedded deployments. Platform and ledger keep their existing schemas, receipts and operation runner. The server has no migration set or worker of its own. A network timeout leaves the outcome unknown, so the client looks up the existing operation and preserves its key and digest. Refunds stay with the original charge executor. (D18, D31, D55, D57, D58; owners core, ledger and execution.)

## Scenario, delivery and execution authority

The A–D labels describe adoption scenarios. Delivery, execution authority and commercial distribution are independently chosen under REQ-PROF-06 and D55. Distribution D1–D6 does not select runtime modules or appoint the executor. The current module descriptors govern supported combinations. (D17, D55, D61; owner architecture.)

| Scenario | Delivery and runtime composition | Writer and limits |
| --- | --- | --- |
| A · Commerce owns execution | Embedded or server with `embedded-commerz` | Commerce retains its operation authority. Billium ledger writes Billium receivables. Server delivery receives authorized external observations. Execution, collection and `WriterAdmission` are forbidden. (D55, D61; owners commerce and ledger.) |
| B · Host journal | Embedded ledger or server with `ledger-only` | The host supplies financial session authority and authenticated external observations when applicable. Billium ledger is the receivables writer. `ledger-only` forbids execution, collection and `WriterAdmission`. (D55, D61, D62; owners host and ledger.) |
| C · Billium-owned operations in the host | Embedded `standalone-billium`, with explicit execution when needed | The host embeds the same Billium writer. Outgoing effects require `WriterAdmission` and a qualified execution configuration. This composition prohibits server delivery. (D18, D55, D57, D62; owners host and execution.) |
| D · Remote Billium execution | Server `remote-billium` with platform, ledger and execution | Billium execution owns effects for admitted operations. Missing execution or `WriterAdmission` refuses readiness. The host keeps original owner bindings through cutover. (D55, D58, D61, D62; owners host and execution.) |

Usage, rating, schedule and documents are optional in these rows. Schedule requires rating and ledger. Documents requires ledger and its own ports. Collection requires ledger and execution. A ledger-only host uses no renderer or provider SDK and registers no execution or collection worker. The core retains history and recovery obligations for previously enabled modules. (D55, D59, D61, D63; owners core and module owners.)

## Host configuration and HTTP

Set `billium.modules.composition`, `billium.modules.enabled` and `billium.connection` for the selected PostgreSQL primary. Build any Laravel route cache with the selected module composition. Bind the server's `Authenticator`, `SellerContextResolver` and `AuthorizationVerdict`, plus the core financial-session and authority-evidence ports. Select seller legal/requisites snapshots, tax and fiscal policy, numbering series, eligible currencies and merchant account mapping through the host and the relevant module interfaces. Seller, customer or payer, actor and usage source are distinct identities. (D23, D55, D57, D62, D63; owners host, ledger, documents and execution.)

Apply the existing core migration sets explicitly using the migration owner. `GET /billium/live` reports the booted HTTP process. `GET /billium/ready` checks the selected PostgreSQL connection and installed module checkpoints using the core compatibility doctor. An unavailable connection, incompatible module configuration, unavailable required port or unapplied migration yields HTTP 503 and blocks server financial requests. The deployment owner still supplies process tokens and deployment evidence. The execution owner enables outgoing effects through core qualification and admission. (D55, D57, D59, D62; owners host, platform and execution.)

The HTTP routes below use the existing wire types and problem registry. The authenticated host resolves the route seller, the request scope must agree, and the authorization verdict binds the action and full payload digest. Advertised capabilities convey no authorization. (D9, D23, D31, D58, D63; owners contracts and host.)

| Route under `POST /billium/v1/sellers/{seller}` | Delegation |
| --- | --- |
| `/query` | `billium.query.v1` through `LedgerQueries` when ledger is enabled. (D23, D55; owner ledger.) |
| `/observations` | `payment.observation.v1` through `ObservationApplier` for externally executed profiles. The request must match one host grant for the channel, authority, installation, environment, seller, exact lineage and partition before custody or replay lookup. Bootstrap and lineage transitions use the core recovery path. The allocation receipt is returned only after core reports business application. (D31, D55, D61, D63, INV-08; owners host, platform and ledger.) |
| `/commands` | Execute, authorize, capture, void, refund and cancel envelopes through the execution command APIs in `remote-billium`. Local cancel and provider void are separate operations. (D55, D57, D58; owner execution.) |
| `/find` | `payment.find.v1` lookup through `ExecutionQueries` in `remote-billium`. The adapter serializes the returned envelope with the contracts codec, including optional fields. (D9, D55, D58; owners contracts and execution.) |

## Ownership vocabulary

| Term | Owner and meaning |
| --- | --- |
| Gateway transport, provider facts | PaymentDrivers handles PSP request/response transport and evidence. The PSP reports authorization, capture and refund facts. (COV-015, COV-023, D57; owners PaymentDrivers and PSP.) |
| PSP effects and unknown recovery | Billium execution or the original external executor, including Commerz for its operations. (COV-016, COV-033, COV-036, COV-037, D57, D58; owner original executor.) |
| Monetary schedule, SaaS PriceVersion, rated charge and invoice | Billium owns monetary calculation and its receivables. Commercial agreement and component generation come from ProductCoordinator. Retail promotions and retail charge snapshots remain with Commerz. (COV-017, COV-025–028, COV-032, COV-034, D55; owners rating, schedule, ledger, ProductCoordinator and Commerz.) |
| Usage measurement and usage reservation | Collectors own measured usage. The product credit owner owns reservation. (COV-018, COV-030–031, D55; owners Collector and product.) |
| Document, allocation and fiscal receipt | Billium documents owns legal document lifecycle. Ledger owns allocation. The fiscal driver acts for the original executor. (COV-019, COV-034–035, COV-038, D63; owners documents, ledger and fiscal driver.) |
| PlanVersion, subscription entitlement and license | Product or Tariffler owns features, coverage, and limits. The licensing authority owns license issuance. A Billium monetary schedule can follow the accepted agreement. (COV-020, COV-024, COV-027–029, COV-039, D55; owners product, Tariffler and licensing authority.) |
| Billing account, PSP merchant account and company books | Ledger owns Billium billing account scopes. Merchant mappings are host inputs with immutable execution lineage. AiFina or the accounting owner owns company accounting and tax policy. (COV-021, COV-041, D57, D60; owners ledger, host, execution and accounting.) |
| Merchant of record and provisioning | The named legal counterparty owns merchant-of-record obligations. The product coordinator owns provisioning. (COV-022, COV-040, D55; owners legal counterparty and product coordinator.) |
| Reward and publisher payout | The product reward ledger owns rewards. Publisher payout is reserved for future Settlement ownership. (COV-042, COV-043, D53; owners product and Settlement.) |

Use “payment operation”, “payment attempt” or “database transaction” for the corresponding identity. Qualify “subscription” as entitlement coverage or monetary schedule, and “account” as billing account or PSP merchant account. (COV-015–023, D55; owners architecture and the owners listed above.)

## Evidence

| Boundary | Evidence class | Subject |
| --- | --- | --- |
| Package dependency direction and public SPI | Static architecture test | `ServerBoundaryTest` checks imports and package requirements, including failing examples for forbidden imports. |
| Delivery and authority combinations | Laravel composition integration test | `Feature/CompositionTest.php` checks route and service registration in A–D and disabled compositions. |
| Schema, seller and source authorization | Laravel/PostgreSQL integration test | `ServerHostTest` covers refusal before migration, seller and source isolation before custody, separate custody namespaces and full module wiring with test ports. |
| Distribution | Package archive install smoke | `scripts/smoke/server-install.sh --profile ledger-only` installs the archives into a clean consumer, discovers the providers and boots a fresh PostgreSQL host. |

The tests exercise deployment composition and HTTP delivery using fixture authority. Live provider and deployment qualification are governed by D59 and D62 (owners execution and deployment).
