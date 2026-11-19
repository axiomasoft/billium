#!/usr/bin/env bash
# Install the standalone PHP and Dart contracts in empty projects. Verify Laravel provider
# discovery and Billium testkit removal from a production installation.
# Run inside the toolchain image:
#   docker compose run --rm toolchain bash scripts/smoke/consumer-install.sh [php|dart]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'find "$WORK" -delete' EXIT

EXECUTE='{"schema":"payment.execute.v1","commandKey":"7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87","operationRef":"op-8f3a21","operationKind":"charge","source":{"context":"commerz","orderRef":"ord-10234","intentRef":"int-10234-1"},"installationRef":"inst-eu-1","environment":"test","executionProfile":"remote-billium","routingEpoch":1,"sellerRef":"seller-acme","merchantAccountRef":"ma-main","money":{"amount":"1490.00","currency":"RUB"},"bindingRevision":"bind-7c91d0e2","returnContextRef":"rc-10234","requestedAt":"2026-10-17T12:00:00Z"}'
export EXECUTE

smoke_php() {
    python3 "$ROOT/scripts/smoke/php-consumer.py" "$WORK/php"
}

smoke_dart() {
    local dir="$WORK/dart" package="$WORK/billium_contracts"
    mkdir -p "$dir/bin" "$package"
    tar -C "$ROOT/packages/contracts-dart" --exclude=.dart_tool --exclude=pubspec.lock --exclude=test -cf - . | tar -C "$package" -xf -
    cat >"$dir/pubspec.yaml" <<YAML
name: consumer_smoke
publish_to: none
environment:
  sdk: ^3.13.0
dependencies:
  billium_contracts:
    path: $package
YAML
    cat >"$dir/bin/smoke.dart" <<'DART'
import 'dart:io';

import 'package:billium_contracts/billium_contracts.dart';

void main() {
  final money = Money.fromWire({'amount': '1490.00', 'currency': 'RUB'});
  if (money.minor != BigInt.from(149000)) {
    stderr.writeln('smoke: money minor');
    exit(1);
  }
  final envelope = const Codec().decode(Platform.environment['EXECUTE']!);
  if (envelope is! ExecutePayment ||
      envelope.money.minor != BigInt.from(149000)) {
    stderr.writeln('smoke: execute decode');
    exit(1);
  }
  stdout.writeln('smoke dart: ok');
}
DART
    (
        cd "$dir"
        dart pub get
        dart run bin/smoke.dart
    )
}

case "${1:-all}" in
    php) smoke_php ;;
    dart) smoke_dart ;;
    all)
        smoke_php
        smoke_dart
        ;;
    *)
        echo "smoke: unknown target: $1" >&2
        exit 1
        ;;
esac
