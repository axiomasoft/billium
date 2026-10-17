#!/usr/bin/env python3
"""Fake conformance adapter for the runner self-tests.

The behavior is chosen per vector by `given.mode`; the start-up is chosen by environment variables:
  FAKE_SPEC_REV   spec_rev in `ready` (default 2026-10)
  FAKE_OPS        comma-separated ops in `ready` (default: all ops used by the tests)
  FAKE_EXIT_CODE  exit code after `stop` (default 0)
  FAKE_START      `crash` exits before `ready`

Modes: echo (default; each step answers `args.result` as ok, or `args.error_code` as error), crash, hang,
garbage, invalid, short, wrong_id.
"""
import json
import os
import sys
import time

OPS = "money.parse,money.format,canon.canonicalize,canon.digest,envelope.roundtrip"


def emit(message):
    sys.stdout.write(json.dumps(message, ensure_ascii=False) + "\n")
    sys.stdout.flush()


def answer(step):
    args = step["args"]
    if "error_code" in args:
        return {"error": {"code": args["error_code"]}}
    return {"ok": args.get("result", {})}


def main():
    if os.environ.get("FAKE_START") == "crash":
        sys.exit(7)
    for raw in sys.stdin:
        message = json.loads(raw)
        kind = message["type"]
        if kind == "start":
            emit(
                {
                    "type": "ready",
                    "adapter": "php",
                    "spec_rev": os.environ.get("FAKE_SPEC_REV", "2026-10"),
                    "ops": os.environ.get("FAKE_OPS", OPS).split(","),
                }
            )
        elif kind == "config":
            emit({"type": "ok"})
        elif kind == "run":
            if "expect" in message:
                emit({"type": "result", "id": message["id"], "outputs": [{"error": {"code": "billium.validation.invalid"}}]})
                continue
            mode = message["given"].get("mode", "echo")
            if mode == "crash":
                sys.exit(3)
            if mode == "hang":
                time.sleep(60)
            if mode == "garbage":
                sys.stdout.write("this is not json\n")
                sys.stdout.flush()
                continue
            if mode == "invalid":
                emit({"type": "result", "id": message["id"]})
                continue
            if mode == "short":
                emit({"type": "result", "id": message["id"], "outputs": []})
                continue
            if mode == "wrong_id":
                emit({"type": "result", "id": "other/id", "outputs": [answer(step) for step in message["script"]]})
                continue
            emit({"type": "result", "id": message["id"], "outputs": [answer(step) for step in message["script"]]})
        elif kind == "stop":
            sys.exit(int(os.environ.get("FAKE_EXIT_CODE", "0")))


if __name__ == "__main__":
    main()
