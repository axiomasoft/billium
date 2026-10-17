"""Adapter protocol: message validation and one adapter process (JSON Lines over stdin/stdout)."""
from __future__ import annotations

import json
import os
import selectors
import subprocess
import time
from pathlib import Path
from typing import Any

from jsonschema import Draft202012Validator
from referencing import Registry
from referencing.jsonschema import DRAFT202012

PROTOCOL = 1


class AdapterError(Exception):
    """The adapter crashed, timed out or broke the protocol; the vector in flight is an `error`."""


class Schemas:
    """Validators over the schema files of one spec revision, resolved offline by their urn $id."""

    def __init__(self, schema_dir: Path) -> None:
        resources = []
        for path in sorted(schema_dir.rglob("*.json")):
            document = json.loads(path.read_text(encoding="utf-8"))
            resources.append((document["$id"], DRAFT202012.create_resource(document)))
        self.registry = Registry().with_resources(resources)
        self.documents = {resource[0]: resource[1].contents for resource in resources}
        self.message = self._validator("conformance/adapter-message.json")
        self.vector = self._validator("conformance/vector.json")

    def _validator(self, rel: str) -> Draft202012Validator:
        suffix = ":" + rel
        for uri, document in self.documents.items():
            if uri.endswith(suffix):
                return Draft202012Validator(document, registry=self.registry)
        raise FileNotFoundError(f"schema not found: {rel}")


def first_error(validator: Draft202012Validator, instance: Any) -> str | None:
    error = next(iter(sorted(validator.iter_errors(instance), key=lambda item: list(item.path))), None)
    if error is None:
        return None
    where = "/".join(str(part) for part in error.path) or "<root>"
    return f"{where}: {error.message}"


class Session:
    """One adapter process after the handshake `start → ready`, `config → ok`."""

    def __init__(self, cmd: list[str], cwd: Path, profile: str, schemas: Schemas, timeout: float) -> None:
        self.cmd = cmd
        self.cwd = cwd
        self.profile = profile
        self.schemas = schemas
        self.timeout = timeout
        self.process: subprocess.Popen[bytes] | None = None
        self.ready: dict[str, Any] = {}
        self._buffer = b""
        self._selector: selectors.BaseSelector | None = None

    def start(self) -> dict[str, Any]:
        try:
            self.process = subprocess.Popen(
                self.cmd,
                cwd=self.cwd,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=None,
            )
        except OSError as error:
            raise AdapterError(f"cannot start adapter {self.cmd!r}: {error}") from error
        assert self.process.stdout is not None
        self._selector = selectors.DefaultSelector()
        self._selector.register(self.process.stdout, selectors.EVENT_READ)

        self.send({"type": "start", "protocol": PROTOCOL})
        ready = self.receive("ready", self.timeout)
        if ready["adapter"] != self.profile:
            raise AdapterError(f"ready.adapter is {ready['adapter']!r}, expected {self.profile!r}")
        self.send({"type": "config", "profile": self.profile})
        self.receive("ok", self.timeout)
        self.ready = ready
        return ready

    def send(self, message: dict[str, Any]) -> None:
        problem = first_error(self.schemas.message, message)
        if problem is not None:
            raise AdapterError(f"runner built an invalid {message.get('type')!r} message: {problem}")
        assert self.process is not None and self.process.stdin is not None
        line = json.dumps(message, ensure_ascii=False, separators=(",", ":")) + "\n"
        try:
            self.process.stdin.write(line.encode("utf-8"))
            self.process.stdin.flush()
        except OSError as error:
            raise AdapterError(f"adapter closed stdin: {error}") from error

    def receive(self, expected_type: str, timeout: float) -> dict[str, Any]:
        line = self._readline(timeout)
        try:
            message = json.loads(line)
        except ValueError as error:
            raise AdapterError(f"stdout line is not JSON: {line[:120]!r}") from error
        problem = first_error(self.schemas.message, message)
        if problem is not None:
            raise AdapterError(f"invalid adapter message: {problem}")
        if message["type"] != expected_type:
            raise AdapterError(f"expected {expected_type!r}, got {message['type']!r}")
        return message

    def run(self, vector_id: str, given: dict[str, Any], script: list[dict[str, Any]]) -> list[dict[str, Any]]:
        self.send({"type": "run", "id": vector_id, "given": given, "script": script})
        result = self.receive("result", self.timeout)
        if result["id"] != vector_id:
            raise AdapterError(f"result id {result['id']!r} does not match run id {vector_id!r}")
        return result["outputs"]

    def stop(self) -> str | None:
        """Ask the adapter to finish; returns a problem description or None when it exited 0."""
        process = self.process
        if process is None:
            return None
        try:
            self.send({"type": "stop"})
        except AdapterError:
            pass
        try:
            code = process.wait(timeout=self.timeout)
        except subprocess.TimeoutExpired:
            self.kill()
            return f"adapter did not exit within {self.timeout:g}s after stop"
        self._release()
        return None if code == 0 else f"adapter exit code {code} after stop"

    def kill(self) -> None:
        process = self.process
        if process is not None and process.poll() is None:
            process.kill()
            process.wait()
        self._release()

    def _release(self) -> None:
        if self._selector is not None:
            self._selector.close()
            self._selector = None
        process = self.process
        if process is not None:
            for stream in (process.stdin, process.stdout):
                if stream is not None:
                    try:
                        stream.close()
                    except OSError:
                        pass
        self.process = None

    def _readline(self, timeout: float) -> str:
        assert self.process is not None and self.process.stdout is not None and self._selector is not None
        deadline = time.monotonic() + timeout
        while b"\n" not in self._buffer:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise AdapterError(f"timeout after {timeout:g}s")
            if not self._selector.select(remaining):
                continue
            chunk = os.read(self.process.stdout.fileno(), 65536)
            if not chunk:
                code = self.process.poll()
                raise AdapterError(f"adapter closed stdout (exit code {code})")
            self._buffer += chunk
        line, _, self._buffer = self._buffer.partition(b"\n")
        try:
            return line.decode("utf-8")
        except UnicodeDecodeError as error:
            raise AdapterError("stdout line is not UTF-8") from error
