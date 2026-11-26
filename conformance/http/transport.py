"""One-request loopback relay. Faults have an explicit upstream checkpoint (D58/D59)."""

from __future__ import annotations

from contextlib import contextmanager
from http.client import HTTPConnection
from http.server import BaseHTTPRequestHandler, HTTPServer
import hashlib
import json
import socket
from threading import Thread
from time import sleep


def digest(data: bytes) -> str:
    return "sha256:" + hashlib.sha256(data).hexdigest()


def json_bytes(value) -> bytes:
    return json.dumps(value, separators=(",", ":"), ensure_ascii=False).encode()


def sealed(body, member="digest"):
    from oracle import canonicalize

    content = {key: value for key, value in body.items() if key != member}
    return {**content, member: digest(canonicalize(json_bytes(content).decode()))}


@contextmanager
def relay(upstream: int, fault: str, mutation: dict):
    trace = {}
    errors = []

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_):
            pass

        def forward(self):
            request = self.rfile.read(int(self.headers.get("Content-Length", "0")))
            trace.update(requestDigest=digest(request), commandKey=self.headers.get("Idempotency-Key"))
            if fault == "retry-after":
                self.reply(503, "application/problem+json", json_bytes({
                    "code": "billium.transport.unavailable", "title": "billium.transport.unavailable",
                    "status": 503, "category": "transport", "retryable": True, "sameKey": True, "receipt": "none",
                }), {"Retry-After": "1"})
                trace["checkpoint"] = "before-upstream"
                return
            conn = HTTPConnection("127.0.0.1", upstream, timeout=20)
            try:
                headers = {key: self.headers[key] for key in
                           ("Authorization", "Content-Type", "Accept", "Idempotency-Key", "X-Fixture-Delivery")
                           if key in self.headers}
                conn.request(self.command, self.path, request, headers)
                response = conn.getresponse()
                body = response.read()
                status, content_type = response.status, response.getheader("Content-Type", "")
                trace.update(checkpoint="upstream-response-complete", upstreamStatus=status,
                             upstreamResponseDigest=digest(body))
            finally:
                conn.close()
            if fault in ("close", "interrupt"):
                return
            if fault == "delay":
                sleep(0.3)
            elif fault == "malformed":
                body = b"[\"truncated"
            elif fault == "mutate" and status < 300:
                parsed = json.loads(body)
                for path, value in mutation["fields"].items():
                    *parents, member = path.split(".")
                    target = parsed
                    for parent in parents:
                        target = target[parent]
                    target[member] = value
                if mutation.get("seal"):
                    parsed = sealed(parsed)
                body = json_bytes(parsed)
            self.reply(status, content_type, body)

        def reply(self, status, content_type, body, headers=None):
            self.send_response(status)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(body)))
            for key, value in (headers or {}).items():
                self.send_header(key, value)
            self.end_headers()
            self.wfile.write(body)

        def do_POST(self):
            try:
                self.forward()
            except (BrokenPipeError, ConnectionResetError):
                if fault not in ("interrupt", "delay") or "upstreamStatus" not in trace:
                    raise
            except Exception as error:
                errors.append(error)

        do_GET = do_POST

    with HTTPServer(("127.0.0.1", 0), Handler) as server:
        server.timeout = 20
        thread = Thread(target=server.handle_request, daemon=True)
        thread.start()
        try:
            yield server.server_port, trace
        finally:
            thread.join(timeout=22)
        if thread.is_alive() or "checkpoint" not in trace:
            raise RuntimeError(f"Relay did not reach its checkpoint: {errors}")
        if errors:
            raise RuntimeError(f"Relay failed: {errors}")


def interrupt(port: int, path: str, headers: dict, body: bytes) -> None:
    """Write the complete request and disconnect before any attempt to read a response."""
    with socket.create_connection(("127.0.0.1", port), timeout=5) as conn:
        wire = [f"POST {path} HTTP/1.0", "Host: 127.0.0.1", f"Content-Length: {len(body)}"]
        wire.extend(f"{key}: {value}" for key, value in headers.items())
        conn.sendall(("\r\n".join(wire) + "\r\n\r\n").encode("ascii") + body)
        conn.shutdown(socket.SHUT_WR)
