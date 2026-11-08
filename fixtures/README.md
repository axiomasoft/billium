# Fixtures

Language-neutral corpus of `billium-contracts` (REQ-CON-03, M-06): the only thing PHP and Dart share besides the
spec revision. Vectors are driven through adapters by `conformance/runner/run.py`; see `conformance/README.md`.

```bash
docker compose run --rm toolchain python3 scripts/spec/lint-vectors.py      # corpus lint (layer lint)
docker compose run --rm toolchain python3 scripts/spec/build-golden.py      # write golden (layer derived: --check)
docker compose run --rm toolchain python3 conformance/runner/run.py --check-golden
```

## Layout

| Path | Content |
|:--|:--|
| `vectors/<set>/<name>.json` | one vector; `id` = path without `.json` |
| `golden/canon/digest/*.txt` | canonical forms and digests created by the JCS oracle |
| `golden/errors/registry.json` | `codes.registry` expectation, generated from `problem-codes.json` |
| `coverage.json` | `{"not_testable": [{"rule", "reason"}]}`: rules no codec vector can check |

## Vector

Valid by `spec/revisions/2026-10/schema/conformance/vector.json`:

```json
{
  "id": "money/scale/parse-error-jpy-extra-fraction",
  "spec_refs": ["BC-MW-03", "BC-MW-07", "REQ-CON-02"],
  "profiles": ["php", "dart"],
  "given": {},
  "script": [{"op": "money.parse", "args": {"wire_text": "{\"amount\":\"1.0\",\"currency\":\"JPY\"}"}}],
  "expect": [{"error": {"code": "billium.money.scale_invalid"}}]
}
```

- `spec_refs`: at least one `BC-*` marker, REQ, INV or D#; each resolves (`rules.json`, `docs/requirements.md`, plan decisions).
- `profiles`: `php` runs the whole corpus, `dart` the codec slice (M-09, M-19). All P2 vectors are codec vectors and run in both.
- `script` steps are `{op, args}`; `expect` has one entry per step: `{"ok": {…}}` (the keys are a subset of the output,
  values are equal exactly) or `{"error": {"code"}}` (the code is compared, `detail` is diagnostic).
- Input where the lexical form matters (a JSON number versus a string) is passed as text: `wire_text`, `json_text`.
- `{"golden": "<path under golden/>"}` as the value of an `ok` field means the output equals the golden bytes (one trailing LF dropped);
  a `.json` golden is compared as a JSON value.
- `control: "must-fail"` marks a control vector: its expectation is wrong on purpose and the run is red if it passes (M-16).

## Ops

| op | args | ok output |
|:--|:--|:--|
| `money.parse` | `wire_text` | `{minor, currency}` (`minor` is an int64 as a string) |
| `money.format` | `minor`, `currency` | `{wire: {amount, currency}}` |
| `money.add` | `a`, `b` (MoneyWire) | `{wire}` |
| `money.allocate` | `total` (MoneyWire), `ratios` (string[]) | `{parts: [MoneyWire…]}` |
| `money.round` | `value`, `currency`, `mode` | `{wire}` |
| `canon.canonicalize` | `json_text` | `{text}` |
| `canon.digest` | `json_text` | `{digest}` (`sha256:` + hex over the canonical form without the top-level `digest`) |
| `envelope.decode` | `json_text` | `{schema, digest}` |
| `envelope.roundtrip` | `json_text` | `{text}` (canonical form after decode → encode) |
| `problem.decode` | `json_text` | `{code, category, retryable, sameKey, receipt}` |
| `codes.registry` | — | `{codes: […]}` in `problem-codes.json` order |

The PHP behavior host boots Testbench and PostgreSQL only on the first `ledger.*` op (BC-CNF-10).
It uses `DB_HOST`, `DB_PORT`, `DB_DATABASE` (ending in `_test`), `DB_USERNAME`, `DB_PASSWORD`,
`DB_OWNER_USERNAME` and `DB_OWNER_PASSWORD` from the test environment. Runtime and owner roles must differ
(D60); missing database configuration or an unavailable database is an adapter `error` (BC-CNF-12).
Every behavior vector starts with `ledger.reset` and supplies
`given.caller: {sellerRef, environment, installationRef, sourceAuthorities: [...]}` (BC-ENV-10).
Reset takes the database test lease, migrates once, and TRUNCATEs Billium tables between vectors without a
transaction wrapper (D50). The adapter is a test host; monetary writes use the real guarded runtime routines (D60).

For a PHP conformance run alongside database tests, use
`php conformance/adapters/php/bin/with-database-lease.php python3 conformance/runner/run.py --adapter php`
(also used by the CI conformance layer). The parent reserves the test database before starting the runner;
adapters verify and join its live lease. Waiting for another suite therefore precedes the 10-second vector
deadline, while committed-data isolation remains exclusive (D50, BC-CNF-12).

| PHP behavior op | args | ok output |
|:--|:--|:--|
| `ledger.reset` | — | `{}` |
| `ledger.caller` | `sellerRef`, `environment`, `installationRef`, `sourceAuthorities` | `{}` |
| `ledger.obligation.record` | `commandKey`, `billingAccountRef`, `obligationRef`, `basisRef`, `customerRef`, `componentRef`, `amount` (MoneyWire), `termsDigest`; optional price/tax refs and due-policy provenance | ObligationRecorded body |
| `ledger.intent.declare` | `commandKey`, `billingAccountRef`, `businessIntentRef`, `currency`, ordered `obligationRefs`, `policyVersion` | SettlementIntentDeclared body |
| `ledger.credit.record` | `commandKey`, `billingAccountRef`, `creditRef`, `sourceRef`, `amount` | CustomerCreditRecorded body |
| `ledger.invoice.create` | `commandKey`, `billingAccountRef`, `invoiceRef`, `customerRef`, `lines: [{obligationRef, taxMinor?, snapshot?}]`, `snapshot?` | InvoiceReceipt body |
| `ledger.invoice.issue` / `.void` / `.metadata` | `commandKey`, `billingAccountRef`, `invoiceRef`; void: `causeRef`; metadata: `updateRef`, `metadata` | InvoiceReceipt body |
| `ledger.allocate` | `commandKey`, `billingAccountRef`, `allocationRef`, `obligationRef`, `amount`, exactly one of `paymentRef` / `customerCreditRef`, `cause?` | AllocationReceipt body |
| `ledger.reverse` | `commandKey`, `billingAccountRef`, `reversalRef`, `allocationRef`, `causeRef`, optional `remainder` MoneyWire | AllocationReceipt body |
| `ledger.credit_note` | `commandKey`, `billingAccountRef`, `creditNoteRef`, `invoiceRef`, `mode`, `lines: [{obligationRef, amount}]`, `reason`, `legalDocumentRef?` | AllocationReceipt body |
| `ledger.reallocate` | `json_text` (`allocation.reallocate.v1`) | AllocationReceipt body |
| `ledger.observe` | `json_text` (`payment.observation.v1`) | AllocationReceipt body plus `receiptRef`, `replayed`, or problem code |
| `ledger.drain` | — | `{drained}`: dependency retries reaching applied |
| `ledger.reconcile_refunds` | optional `after_seconds` consistency window | `{discrepancies}`: newly recorded comparisons |
| `ledger.query` | `query`, `selector`; due queries also `asOf`, `limit`, optional `cursor` | `billium.query.v1` result; `query: receipt` uses `selector.receiptRef` |
| `ledger.bootstrap` | `action: stage/step/resume`, `bootstrapRef`, `sourceAuthority`, `sourceLineage`, `partitionRef`, `cursorSequence`, `sourceRetentionLeaseRef`, `items` (observation objects), optional `snapshotDigest`, `expectedItemCount` | BootstrapStatus (D60, REQ-FAIL-23) |

Root command bodies also expose `receiptClass`, `receiptId`, `replayed` for immutable receipt checks
(BC-ID-05…08). Command amounts use MoneyWire. Bootstrap `step` stages/resumes and disposes one item in
its own root; `resume` completes remaining items, adopts the cursor and drains the tail (D60).

Decode checks in order: JSON syntax → `schema` → fields → amounts (BC-ENV-03). A syntax error, a missing or non-string
`schema` and a field violation give `billium.validation.invalid`; a `schema` outside the revision gives
`billium.schema.unsupported`; a MoneyWire form violation with otherwise valid fields, and a malformed `payment.outcome.v1`
total, give `billium.money.scale_invalid`. A `digest` carried by a command must equal the computed one (BC-ID-17). The digest
of an open envelope (outcome, admission, receipt, event) is computed over the received object, unknown optional members included.

## Sets

| Set | Content |
|:--|:--|
| `money/scale`, `money/bounds`, `money/allocation`, `money/rounding` | MoneyWire parse/format, int64 and > 2^53, largest remainder, HALF_EVEN/HALF_UP |
| `canon/digest` | JCS canonical form and digest: key order by UTF-16 code units, escaping, nesting, `digest` exclusion, control vector |
| `payment/execute` | decode of execute, refund, cancel and find (request and response) with the unknown-member (M-05), missing-field, amount-as-number and `v2` cases |
| `payment/admission`, `payment/outcome` | decode of the response envelopes; additive optional members are accepted |
| `usage/fact` | `usage.fact.v1` and `usage.receipt.v1` |
| `errors/codes` | registry golden and `problem.decode` of every code (receipt policy D31) |
| `compat/pilot` | pilot (SRC-005) forms with concrete values: PB-15 |
| `obligation/prepare`, `occurrence/ensure`, `collection/authorize`, `payment/observation`, `query/read`, `event/envelope` | decode of the remaining envelopes of the revision, each closed command with an unknown-member vector (M-05) |
| `ledger/conservation` (PHP) | invoice partial payments, overpayment, refund reallocation, prepaid credits, bundle Hamilton tie, credit notes, frozen metadata, idempotent receipt/refusal replay and seller/account isolation (REQ-ACC-10, INV-09, INV-14) |
| `payment/observation/apply-*` (PHP) | custody/revision dedupe, contiguous sequence and recovery, staged bootstrap/resume, missing-intent observed visibility, assigned totals, last-consistent held projection and delayed refund comparison bases (INV-06, D41, D48, D60) |

## Golden

Golden is authoritative and created only by `scripts/spec/build-golden.py`: `canon/*` by the vendored oracle
(`conformance/oracles/jcs`, M-15), `errors/registry.json` from `problem-codes.json`. Golden is never regenerated from
PHP or Dart output and never edited by hand; the layer `derived` runs `build-golden.py --check`. The golden of the control vector is the
oracle digest with its last hex digit changed. Golden of any set whose value the oracle can derive lives under `golden/canon/`
(`canon/digest/<vector>-canonical.txt`, `-digest.txt`).

Behavioral monetary expectations live directly in `expect` and come from the independent worked examples
(REQ-ACC-10, M-15): 30.00 then cumulative 100.00 allocates 30.00 + 70.00; 120.00 against 100.00 leaves
20.00; credit 50.00 funds 20.00 + 30.00; bundle 6667/3333 splits 5000 into 3334/1666, then 3333/1667.
Each pro-rata example checks `money.allocate` before the ledger operations (D46).
The exceptional refund example preserves observed 140.00 and applied capture100/refund70, with exposure40
and spendable0 (D60). No expectation is copied from the PHP adapter, and `conformance/baseline/php.json`
remains empty (BC-CNF-08, BC-CNF-13).

## Deferred vectors

Behavioral vectors need storage and the command life cycle; they are written in the phase that builds it and are
not in the P2 codec corpus. P3 ledger behavior is now included in the PHP profile (BC-CNF-10).
Remaining deferred rules are listed in `coverage.json` with the reason.

| Vectors | Phase |
|:--|:--|
| `payment/admission` duplicate, conflict, expired, in_flight; idempotency scope and receipt replay (BC-ID-02…09) | P5 |
| `payment/outcome` monotonic `revision`, quarantine of the same revision with another digest | P5 |
| `usage/fact` identity: duplicate and `billium.usage.conflict` (BC-ID-11) | P5 |
