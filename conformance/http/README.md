# HTTP conformance

Run the PHP client against the PostgreSQL test stand with `scripts/test/server-http.sh`. The Docker toolchain also supports `docker compose run --rm toolchain bash scripts/test/server-http.sh --client dart`. Use `--suite HTTP-04` for one fixture and `--report build/http/response-loss.json` to choose the report path.

The runner uses the owner and runtime roles from the PostgreSQL test stand. Each case gets its own database, Laravel host and provider journal. The test host listens on loopback and all its policy and provider inputs are fixtures. The PostgreSQL process remains outside the disposable Laravel hosts.

The JSON fixtures under `fixtures/integration/http/vectors` contain the golden assertions. A `send` step prepares an exact body and key. A later `send` using `use` reuses those bytes and may change credentials or the delivery identifier. Expected paths use dots to descend into objects and arrays, `#` for size and `{"$ref":"step.path"}` for a value learned in an earlier step. The assertions describe the expected behavior independently of the client codec (D9, D29, D31, D58).

The relay records when it receives the complete upstream response. HTTP-04 checks the separate PostgreSQL snapshot before replay to establish the commit checkpoint (D58). HTTP-07 closes the client connection after writing the request, then uses the original attempt and verifies one provider call and one lookup (D57). HTTP-13 reads negotiated v1/v2 selections and checks observed capture against the latest consistent ledger projection. HTTP-19 covers the external source path for actual capture and refund discrepancies (D57, D63).

Reports separate `server-runtime` and `transport-contract` loopback evidence from `provider-double` and `host-double` evidence. The host provisioning fixture records the durable grant and allocation receipt binding independently of Billium and tests a lost host reply (COV-AC-524). The report includes digests for the spec lock, fixtures, harness, package trees and dependency lock. Only the required suite with every case passed can set `requiredSuitePassed` (D59).

The admission/drain integration test uses the P3 concurrency stand. It observes PostgreSQL blocking in both commit orders and checks the durable command claim, attempt and replay behavior (D55, D62).
