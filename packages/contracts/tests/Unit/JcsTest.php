<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

it('sorts keys by UTF-16 code units, numeric-looking keys as text', function (): void {
    $text = '{"￠":1,"😀":2,"é":3,"a":4,"B":5,"10":6,"9":7,"":8}';

    expect((new Codec)->canonicalize($text))->toBe('{"":8,"10":6,"9":7,"B":5,"a":4,"é":3,"😀":2,"￠":1}');
});

it('keeps empty objects and empty lists apart', function (): void {
    expect((new Codec)->canonicalize('{"o":{},"a":[]}'))->toBe('{"a":[],"o":{}}');
});

it('escapes control characters in lowercase hex and leaves the solidus alone', function (): void {
    expect((new Codec)->canonicalize('["\u000f\u001F\u007f\/ \"\\\\"]'))->toBe("[\"\\u000f\\u001f\x7f/\u{2028}\\\"\\\\\"]");
});

it('canonicalizes a PHP array like the same JSON text', function (): void {
    $codec = new Codec;

    expect($codec->canonicalize(['b' => [1, 2], 'a' => null]))->toBe($codec->canonicalize('{"b":[1,2],"a":null}'));
});

it('refuses numbers that are not exact integers', function (string $text): void {
    try {
        (new Codec)->canonicalize($text);
    } catch (ProblemException $exception) {
        expect($exception->problem)->toBe(ProblemCode::ValidationInvalid);

        return;
    }

    throw new LogicException('expected a ProblemException');
})->with([
    'fraction' => ['{"a":1.5}'],
    'zero fraction' => ['{"a":1.0}'],
    'exponent' => ['[1e2]'],
    'above 2^53' => ['[9007199254740993]'],
    'below -2^53' => ['[-9007199254740992]'],
    'above int64' => ['[18446744073709551616]'],
    'syntax error' => ['{"a":'],
]);

it('keeps the digest of the worked example of', function (): void {
    $json = '{"schema":"payment.execute.v1","commandKey":"0b6f2c1e-6d1a-4a44-9a51-3f0c6f2f7a10","operationRef":"op-1","operationKind":"charge","source":{"context":"commerz","orderRef":"ord-1","intentRef":"int-1"},"installationRef":"inst-1","environment":"test","executionProfile":"remote-billium","routingEpoch":1,"sellerRef":"seller-1","merchantAccountRef":"ma-1","money":{"amount":"1490.00","currency":"RUB"},"bindingRevision":"sha256:00","requestedAt":"2026-10-17T12:00:00Z"}';

    expect((new Codec)->digest($json))->toBe('sha256:4e51ca0273f40946bfc6ada7f414e9fd8d6228786142c0391070fa51132f4b52');
});

it('leaves a nested digest member in place', function (): void {
    $codec = new Codec;

    expect($codec->digest('{"outer":{"digest":"x"},"digest":"y"}'))->toBe($codec->digest('{"outer":{"digest":"x"}}'));
});
