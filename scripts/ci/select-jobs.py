#!/usr/bin/env python3
"""Turn the affected layers into the job and matrix selection of .github/workflows/ci.yml.

Usage:
  python3 scripts/ci/select-jobs.py --event EVENT --layers "php conformance" \\
      [--toolchain] [--shards 8] [--only "php-pg:3 conformance-integration"]

Prints GitHub output lines (key=value). Boolean keys switch single jobs, JSON-array keys
feed matrices. --only (manual runs) narrows the selection to the named jobs or matrix
cells, so a fix can be re-proved without rerunning everything: layer names (lint,
derived, dart), ci.sh step names, php-pg:<shard>, toolchain and workflows.
"""

from __future__ import annotations

import argparse
import json
import sys

ANALYSE = ["php-validate", "php-phpstan"]
QUALITY = ["php-pint", "php-rector", "php-contracts"]
SMOKE = ["php-smoke-consumer", "php-smoke-server"]
CONFORMANCE = ["conformance-vectors", "conformance-server", "conformance-integration"]
LAYERS = {"lint", "derived", "php", "dart", "conformance"}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--event", required=True)
    parser.add_argument("--layers", default="")
    parser.add_argument("--toolchain", action="store_true")
    parser.add_argument("--shards", type=int, default=8)
    parser.add_argument("--only", default="")
    args = parser.parse_args()

    layers = set(args.layers.split())
    unknown = layers - LAYERS
    if unknown:
        print(f"select-jobs: unknown layer(s): {' '.join(sorted(unknown))}", file=sys.stderr)
        return 2
    if args.shards < 1:
        print("select-jobs: --shards must be positive", file=sys.stderr)
        return 2

    only = set(args.only.replace(",", " ").split())
    php = "php" in layers
    candidates: dict[str, bool] = {
        "workflows": True,
        "toolchain": args.toolchain,
        "lint": "lint" in layers,
        "derived": "derived" in layers,
        "dart": "dart" in layers,
        "php-unit": php,
        "php-pg-serial": php,
        **{step: php for step in ANALYSE + QUALITY + SMOKE},
        **{f"php-pg:{shard}": php for shard in range(1, args.shards + 1)},
        **{step: "conformance" in layers for step in CONFORMANCE},
    }
    unknown_only = only - candidates.keys()
    if unknown_only:
        print(f"select-jobs: unknown --only name(s): {' '.join(sorted(unknown_only))}", file=sys.stderr)
        return 2

    def picked(name: str) -> bool:
        return candidates[name] and (not only or name in only)

    def matrix(names: list[str]) -> str:
        return json.dumps([name for name in names if picked(name)], separators=(",", ":"))

    shards = [shard for shard in range(1, args.shards + 1) if picked(f"php-pg:{shard}")]
    outputs = {
        "workflows": picked("workflows"),
        "toolchain": picked("toolchain"),
        "lint": picked("lint"),
        "derived": picked("derived"),
        "dart": picked("dart"),
        "unit": picked("php-unit"),
        "pg-serial": picked("php-pg-serial"),
        "analyse": matrix(ANALYSE),
        "quality": matrix(QUALITY),
        "smoke": matrix(SMOKE),
        "conformance": matrix(CONFORMANCE),
        "shards": json.dumps(shards, separators=(",", ":")),
        "shard-total": args.shards,
        # Coverage roughly doubles test time: pull requests skip it, main and manual runs collect it.
        "coverage": args.event != "pull_request",
    }
    for key, value in outputs.items():
        text = str(value).lower() if isinstance(value, bool) else str(value)
        print(f"{key}={text}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
