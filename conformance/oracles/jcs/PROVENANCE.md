# Provenance — JCS oracle

Reference implementation of RFC 8785 (JSON Canonicalization Scheme) used as the independent oracle of
the conformance layer (D32, M-15). Golden files under `fixtures/golden/canon/` are created with this
code only; the PHP and Dart SDKs never regenerate them.

Upstream: https://github.com/cyberphone/json-canonicalization

Commit: 32a48195b9b20fe67b1795e39e4509f14d860b45

Path: `python3/src/org/webpki/json/`

- Date retrieved: 2026-10-17 (UTC), from `https://raw.githubusercontent.com/cyberphone/json-canonicalization/32a48195b9b20fe67b1795e39e4509f14d860b45/python3/src/org/webpki/json/<file>`
- License: Apache-2.0 (`org/webpki/json/LICENSE`); `LICENSE.PSF` covers the parts derived from CPython's `json` encoder.

| File | sha256 |
|:--|:--|
| `org/webpki/json/Canonicalize.py` | 644508c81fa4afa50e8f6c7626a8cb6ddbd9515b39128c55484222ad336fad35 |
| `org/webpki/json/NumberToJson.py` | b8f78dab5bd7cf32cc620df39db18c8a55c777fa8acb69294d1a4d6702ee7e2a |
| `org/webpki/json/__init__.py` | 01ba4719c80b6fe911b091a7c05124b64eeece964e09c058ef8f9805daca546b |
| `org/webpki/json/LICENSE` | cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30 |
| `org/webpki/json/LICENSE.PSF` | af99833f38f9849acc41e2ca1de50a3cb266e712713efa6f377bae4befb16482 |

## Rules

- The vendored files are byte-identical to upstream at the pinned commit and are not edited.
- `oracle.py` is the only Billium code here: `canonicalize(text: str) -> bytes` parses JSON text
  (rejecting duplicate keys and non-finite numbers) and calls the upstream `canonicalize`.
- `org/__init__.py` and `org/webpki/__init__.py` are empty package markers (not upstream files).
- Updating the pin means re-downloading the files, updating this table and re-checking every golden file
  (`python3 conformance/runner/run.py --check-golden`).
