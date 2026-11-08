<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\BilliumQuery;
use Billium\Contracts\Data\Envelopes\DueObligations;
use Billium\Contracts\Data\Envelopes\PaymentStatus;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

dataset('ledger read forms', function (): array {
    $root = dirname(__DIR__, 4).'/fixtures/vectors/query/read/';
    $paths = array_merge(glob($root.'payment-status-*.json'), glob($root.'due-obligations-*.json'), [$root.'due-parameters-on-existing-query.json']);

    return array_map(fn (string $path): array => [$path], $paths);
});

it('decodes the D60 read vectors and preserves additive response fields', function (string $path): void {
    $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $json = $vector['script'][0]['args']['json_text'];
    $expected = $vector['expect'][0];
    $codec = new Codec;
    if (isset($expected['error'])) {
        try {
            $codec->decode($json);
        } catch (ProblemException $exception) {
            expect($exception->problem->value)->toBe($expected['error']['code']);

            return;
        }
        throw new LogicException('expected refusal: '.$vector['id']);
    }

    $decoded = $codec->decode($json);
    expect($decoded->schema)->toBe($expected['ok']['schema']);
    $class = match ($decoded->schema) {
        'ledger.payment_status.v1' => PaymentStatus::class,
        'ledger.due_obligations.v1' => DueObligations::class,
        default => BilliumQuery::class,
    };
    expect($decoded)->toBeInstanceOf($class)
        ->and($codec->encode($decoded))->toBe($codec->canonicalize($json));
})->with('ledger read forms');

it('requires an account for a current projection and prevents spending unapplied funds', function (string $vectorName): void {
    $path = dirname(__DIR__, 4).'/fixtures/vectors/query/read/'.$vectorName.'.json';
    $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $input = json_decode($vector['script'][0]['args']['json_text'], true, 512, JSON_THROW_ON_ERROR);
    if ($vectorName === 'payment-status-current') {
        unset($input['result']['billingAccountRef']);
    } else {
        $input['result']['spendable']['amount'] = '1.00';
    }

    try {
        (new Codec)->decode(json_encode($input, JSON_THROW_ON_ERROR));
    } catch (ProblemException $exception) {
        expect($exception->problem)->toBe(ProblemCode::ValidationInvalid);

        return;
    }
    throw new LogicException('expected unsafe projection refusal');
})->with(['payment-status-current', 'payment-status-unapplied']);

it('keeps exceptional actual facts visible without rewriting the last applied totals', function (): void {
    $path = dirname(__DIR__, 4).'/fixtures/vectors/query/read/payment-status-held.json';
    $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $decoded = (new Codec)->decode($vector['script'][0]['args']['json_text']);
    expect($decoded)->toBeInstanceOf(PaymentStatus::class)
        ->and($decoded->result->observed->refundedTotal->amount)->toBe('140.00')
        ->and($decoded->result->applied->capturedTotal->amount)->toBe('100.00')
        ->and($decoded->result->applied->refundedTotal->amount)->toBe('70.00')
        ->and($decoded->result->exposure->overRefunded->amount)->toBe('40.00')
        ->and($decoded->result->financialHold->active)->toBeTrue()
        ->and($decoded->result->spendable->amount)->toBe('0.00');
});
