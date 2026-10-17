<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Envelopes\FindOperation;
use Billium\Contracts\Data\Envelopes\FindResult;
use Billium\Contracts\Data\Envelopes\PaymentOutcome;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

const EXECUTE = '{"schema":"payment.execute.v1","commandKey":"0b6f2c1e-6d1a-4a44-9a51-3f0c6f2f7a10","operationRef":"op-1","operationKind":"charge","source":{"context":"commerz","orderRef":"ord-1","intentRef":"int-1"},"installationRef":"inst-1","environment":"test","executionProfile":"remote-billium","routingEpoch":1,"sellerRef":"seller-1","merchantAccountRef":"ma-1","money":{"amount":"1490.00","currency":"RUB"},"bindingRevision":"sha256:00","requestedAt":"2026-10-17T12:00:00Z"}';

/**
 * @param  array<string, mixed>  $changes  members to set; a null value removes the member
 */
function envelope(string $base, array $changes): string
{
    $data = json_decode($base, true, 512, JSON_THROW_ON_ERROR);
    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }
    }

    return json_encode($data, JSON_THROW_ON_ERROR);
}

function decodeRefusal(string $json): ProblemCode
{
    try {
        (new Codec)->decode($json);
    } catch (ProblemException $exception) {
        return $exception->problem;
    }

    throw new LogicException('expected a ProblemException');
}

it('decodes a command into its typed envelope', function (): void {
    $execute = (new Codec)->decode(EXECUTE);

    expect($execute)->toBeInstanceOf(ExecutePayment::class)
        ->and($execute->schema)->toBe('payment.execute.v1')
        ->and($execute->commandKey)->toBe('0b6f2c1e-6d1a-4a44-9a51-3f0c6f2f7a10')
        ->and($execute->routingEpoch)->toBe(1)
        ->and($execute->money->minor)->toBe(149000)
        ->and($execute->digest)->toBeNull()
        ->and($execute->returnContextRef)->toBeNull();
});

it('accepts the digest of the canonical form and refuses another', function (): void {
    $codec = new Codec;
    $digest = $codec->digest(EXECUTE);

    expect($codec->decode(envelope(EXECUTE, ['digest' => $digest]))->digest)->toBe($digest)
        ->and(decodeRefusal(envelope(EXECUTE, ['digest' => 'sha256:'.str_repeat('0', 64)])))->toBe(ProblemCode::ValidationInvalid);
});

it('picks the find request or response by the status member', function (): void {
    $codec = new Codec;
    $request = '{"schema":"payment.find.v1","installationRef":"i","environment":"test","sellerRef":"s","operationRef":"op-1"}';
    $absent = '{"schema":"payment.find.v1","operationRef":"op-1","status":"absent","absenceCertificate":{"scope":"retention"}}';

    expect($codec->decode($request))->toBeInstanceOf(FindOperation::class)
        ->and($codec->decode($absent))->toBeInstanceOf(FindResult::class);
});

it('keeps unknown optional members of an open envelope and refuses them on a command', function (): void {
    $codec = new Codec;
    $outcome = '{"schema":"payment.outcome.v1","operationRef":"op-1","revision":3,"status":"succeeded","money":{"amount":"1490.00","currency":"RUB"},"capturedTotal":"1490.00","refundedTotal":"0.00","bindingRevision":"sha256:00","sellerRef":"s","merchantAccountRef":"ma-1","providerEvidenceRef":"ev-1","occurredAt":"2026-10-17T12:03:41Z","digest":"sha256:'.str_repeat('a', 64).'","future":{"x":1}}';
    $decoded = $codec->decode($outcome);

    expect($decoded)->toBeInstanceOf(PaymentOutcome::class)
        ->and($codec->encode($decoded))->toContain('"future":{"x":1}')
        ->and(decodeRefusal(envelope(EXECUTE, ['future' => 1])))->toBe(ProblemCode::ValidationInvalid);
});

it('reports the first failed stage: syntax, schema, fields, amounts', function (): void {
    expect(decodeRefusal('{'))->toBe(ProblemCode::ValidationInvalid)
        ->and(decodeRefusal('[]'))->toBe(ProblemCode::ValidationInvalid)
        ->and(decodeRefusal('{"schema":7}'))->toBe(ProblemCode::ValidationInvalid)
        ->and(decodeRefusal(envelope(EXECUTE, ['schema' => 'payment.execute.v2', 'money' => null])))->toBe(ProblemCode::SchemaUnsupported)
        ->and(decodeRefusal(envelope(EXECUTE, ['money' => ['amount' => '1490.0', 'currency' => 'RUB'], 'sellerRef' => null])))->toBe(ProblemCode::ValidationInvalid)
        ->and(decodeRefusal(envelope(EXECUTE, ['money' => ['amount' => '1490.0', 'currency' => 'RUB']])))->toBe(ProblemCode::MoneyScaleInvalid)
        ->and(decodeRefusal(envelope(EXECUTE, ['money' => ['amount' => '0.00', 'currency' => 'RUB']])))->toBe(ProblemCode::ValidationInvalid);
});

it('refuses an integer member outside the exact IEEE 754 range', function (): void {
    expect(decodeRefusal(str_replace('"routingEpoch":1', '"routingEpoch":9007199254740992', EXECUTE)))->toBe(ProblemCode::ValidationInvalid)
        ->and((new Codec)->decode(str_replace('"routingEpoch":1', '"routingEpoch":9007199254740991', EXECUTE)))->toBeInstanceOf(ExecutePayment::class);
});

it('checks outcome totals against the scale of the money currency', function (): void {
    $outcome = '{"schema":"payment.outcome.v1","operationRef":"op-1","revision":3,"status":"succeeded","money":{"amount":"1490","currency":"JPY"},"capturedTotal":"1490","refundedTotal":"0","bindingRevision":"b","sellerRef":"s","merchantAccountRef":"ma-1","providerEvidenceRef":"ev-1","occurredAt":"2026-10-17T12:03:41Z","digest":"sha256:'.str_repeat('a', 64).'"}';

    expect((new Codec)->decode($outcome))->toBeInstanceOf(PaymentOutcome::class)
        ->and(decodeRefusal(str_replace('"capturedTotal":"1490"', '"capturedTotal":"1490.00"', $outcome)))->toBe(ProblemCode::MoneyScaleInvalid)
        ->and(decodeRefusal(str_replace('"capturedTotal":"1490"', '"capturedTotal":1490', $outcome)))->toBe(ProblemCode::MoneyScaleInvalid);
});

it('decodes a problem only when it matches its registry entry', function (): void {
    $codec = new Codec;
    $problem = '{"code":"billium.idempotency.in_flight","category":"idempotency","retryable":true,"sameKey":true,"receipt":"none","title":"In flight","status":409}';

    expect($codec->decodeProblem($problem)->code)->toBe(ProblemCode::IdempotencyInFlight)
        ->and($codec->decodeProblem($problem)->status)->toBe(409);

    $broken = str_replace('"retryable":true', '"retryable":false', $problem);
    try {
        $codec->decodeProblem($broken);
    } catch (ProblemException $exception) {
        expect($exception->problem)->toBe(ProblemCode::ValidationInvalid);

        return;
    }

    throw new LogicException('expected a ProblemException');
});
