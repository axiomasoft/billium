#!/usr/bin/env bash
# Consumer install smoke (P2.8, PB-15 support): install each contracts package into an empty project
# the way a consumer would, by copy and without dev dependencies, and use its public API.
# Run inside the toolchain image:
#   docker compose run --rm toolchain bash scripts/smoke/consumer-install.sh [php|dart]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'find "$WORK" -delete' EXIT

EXECUTE='{"schema":"payment.execute.v1","commandKey":"7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87","operationRef":"op-8f3a21","operationKind":"charge","source":{"context":"commerz","orderRef":"ord-10234","intentRef":"int-10234-1"},"installationRef":"inst-eu-1","environment":"test","executionProfile":"remote-billium","routingEpoch":1,"sellerRef":"seller-acme","merchantAccountRef":"ma-main","money":{"amount":"1490.00","currency":"RUB"},"bindingRevision":"bind-7c91d0e2","returnContextRef":"rc-10234","requestedAt":"2026-10-17T12:00:00Z"}'
export EXECUTE

smoke_php() {
    local dir="$WORK/php"
    local extra_repos=""
    if [[ -d "$ROOT/vendor/brick/money" ]]; then
        local brick_money_version
        brick_money_version="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach ($d["packages"]??$d as $p) { if (($p["name"]??"")==="brick/money") { echo ltrim((string)$p["version"],"v"); break; } }' "$ROOT/vendor/composer/installed.json")"
        if [[ -n "$brick_money_version" ]]; then
            extra_repos=",{\"type\":\"path\",\"url\":\"$ROOT/vendor/brick/money\",\"options\":{\"symlink\":false,\"versions\":{\"brick/money\":\"$brick_money_version\"}}}"
        fi
    fi
    mkdir -p "$dir"
    cat >"$dir/composer.json" <<JSON
{
    "name": "consumer/billium-smoke",
    "require": {"axiomasoft/billium-contracts": "*@dev"},
    "repositories": [
        {"type": "path", "url": "$ROOT/packages/contracts", "options": {"symlink": false}},
        {"type": "path", "url": "$ROOT/../packman/packages/core", "options": {"symlink": false, "versions": {"axiomasoft/packman": "0.1.x-dev"}}}
        $extra_repos
    ],
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON
    (
        cd "$dir"
        COMPOSER_DISABLE_NETWORK=1 composer install --no-dev --no-interaction --no-progress
        if [[ -L vendor/axiomasoft/billium-contracts ]]; then
            echo "smoke: package installed as symlink" >&2
            exit 1
        fi
        if [[ -e vendor/laravel/framework || -e vendor/illuminate ]]; then
            echo "smoke: laravel/framework or illuminate in the consumer vendor (REQ-CON-01)" >&2
            exit 1
        fi
        php -r '
            require "vendor/autoload.php";
            $money = Billium\Contracts\Data\Money::fromWire(["amount" => "1490.00", "currency" => "RUB"]);
            if ($money->minor !== 149000) { fwrite(STDERR, "smoke: money minor\n"); exit(1); }
            $envelope = (new Billium\Contracts\Contracts\Codec)->decode(getenv("EXECUTE"));
            if (! $envelope instanceof Billium\Contracts\Data\Envelopes\ExecutePayment || $envelope->money->minor !== 149000) {
                fwrite(STDERR, "smoke: execute decode\n"); exit(1);
            }
            echo "smoke php: ok\n";
        '
    )
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
