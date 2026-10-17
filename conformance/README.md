# Conformance

Cross-language proof that the PHP and Dart `billium-contracts` packages behave as the spec revision says
(M-09, M-11, M-12, M-16). The only things PHP and Dart share are the revision `spec/revisions/<rev>/` and
the corpus `fixtures/`; the runner is Python 3 and runs inside the toolchain image.

```bash
docker compose run --rm toolchain python3 -m unittest discover -s conformance/tests -v   # runner self-tests
docker compose run --rm toolchain python3 conformance/runner/run.py --check-golden        # oracle vs golden
docker compose run --rm toolchain python3 conformance/runner/run.py --adapter php         # one adapter
docker compose run --rm toolchain bash scripts/ci.sh --layer conformance                  # the whole layer
```

## Layout

| Path | Content |
|:--|:--|
| `runner/run.py` | CLI and orchestration; `protocol.py` (adapter process, message validation), `vectors.py` (load, golden, compare), `gate.py` (baseline, lockstep, golden check) |
| `oracles/jcs/` | vendored cyberphone RFC 8785 oracle, pinned in `PROVENANCE.md`; `oracle.py` is the only Billium code: `canonicalize(text) -> bytes` |
| `adapters.json` | adapter name → command, run from the repository root inside the toolchain image |
| `adapters/<name>/` | adapters (P2.5 PHP, P2.6 Dart) |
| `baseline/<adapter>.json` | known not-pass vectors of phases not started yet |
| `reports/<adapter>.json` | run report; git-ignored |
| `tests/` | `unittest` self-tests with `fake_adapter.py` |

## Protocol (JSON Lines, one message per line, stderr is diagnostics only)

1. runner → `{"type":"start","protocol":1}`; adapter → `{"type":"ready","adapter","spec_rev","ops"}`
2. runner → `{"type":"config","profile"}`; adapter → `{"type":"ok"}`
3. per vector of the profile: runner → `{"type":"run","id","given","script"}` (never `expect`); adapter →
   `{"type":"result","id","outputs"}` with `{"ok": …}` or `{"error": {"code","detail"}}` per step
4. runner → `{"type":"stop"}`; the adapter exits 0.

Every message in both directions is validated against `schema/conformance/adapter-message.json`.

## Statuses

`pass`, `fail`, `error`; there is no `skip`.

| Status | When |
|:--|:--|
| `fail` | outputs differ from `expect`, wrong outputs length, or a step uses an op the adapter did not announce in `ready.ops` (the vector is not sent) |
| `error` | adapter crash or exit, timeout (10 s per vector, `--timeout`), non-JSON or schema-invalid message, result id mismatch, invalid vector file, missing or malformed golden |

After an `error` the adapter process is killed and restarted for the next vector. If the adapter cannot
start, every remaining vector is an `error`.

## Comparison

`ok` is partial at the top level: the keys of `expect.ok` are a subset of the output keys and the values are
equal exactly (types strict: `1`, `1.0` and `true` differ; nested objects and arrays are compared whole).
`error` compares `code`; `detail` is diagnostic. A value `{"golden": "<set>/<name>.<ext>"}` is replaced by the
golden file under `fixtures/golden/` before comparing: UTF-8 without BOM and exactly one trailing LF, which is
dropped. A golden whose name ends in `.json` (for example `errors/registry.json`) is parsed and compared as a JSON value instead of as text.

## What makes a run red

The report is `{evidence_class: "contracts-conformance", adapter, spec_rev, counts, results, violations}`;
it makes no exactly-once claim and is not a PHP/Dart parity statement (M-14). The run is green only if
`violations` is empty:

- **Baseline** (M-11): the set of not-pass vectors equals the baseline ids. A not-pass vector without an entry, or
  an entry whose vector passed or no longer exists, is red. Entries carry `id, reason, owner, since, issue,
  phase`; with `--phase Pn` an entry must belong to a later phase. A baseline entry with `spec_refs` to an INV of
  money correctness blocks P10. A missing baseline file is an empty baseline.
- **Control vectors** (M-16): `control: "must-fail"` must end `fail` in every adapter. A control that passes is red;
  a control that errors (crash, timeout) is a not-pass vector and is red like any other error. A profile without any
  control vector is red.
- **Lockstep** (M-12): `revision.lock.json` `revision`, `packages/contracts/composer.json`
  `extra.billium.spec_rev`, `packages/contracts-dart/pubspec.yaml` `billium.spec_rev` and the adapter's
  `ready.spec_rev` are equal.
- **No subject**: no adapter command, no vector for the profile, an adapter that does not exit 0 after `stop`.

## Golden and the oracle (M-15)

`run.py --check-golden` reproduces every golden under `fixtures/golden/canon/` with the vendored oracle and is red on
a difference, on a golden no vector references, or when nothing was checked. Oracle-derivable fields:
`canon.canonicalize` / `envelope.roundtrip` → `text`; `canon.digest` / `envelope.decode` → `digest` (`sha256:` +
SHA-256 of the canonical form without the top-level `digest`, D32). Control vectors are skipped. Golden is created
by the oracle only (`scripts/spec/build-golden.py`); SDK output never regenerates it.
