<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\ReallocatePayment;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

dataset('ledger contract additions', function (): array {
    $root = dirname(__DIR__, 4).'/fixtures/vectors/';
    $paths = glob($root.'allocation/reallocate/*.json');
    foreach (['allocation-exceeds_open', 'allocation-exceeds_available', 'invoice-transition_invalid', 'reference-unknown'] as $name) {
        $paths[] = $root.'errors/codes/decode-'.$name.'.json';
    }

    return array_map(fn (string $path): array => [$path], $paths);
});

it('decodes the D47 vectors with their independent expectations', function (string $path): void {
    $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $step = $vector['script'][0];
    $json = $step['args']['json_text'];
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

    if ($step['op'] === 'envelope.decode') {
        $decoded = $codec->decode($json);
        $input = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        expect($decoded)->toBeInstanceOf(ReallocatePayment::class)
            ->and($decoded->schema)->toBe($expected['ok']['schema'])
            ->and($codec->digestOf($decoded))->toBe($expected['ok']['digest'])
            ->and($decoded->paymentRef)->toBe($input['paymentRef'])
            ->and($decoded->policyVersion)->toBe($input['policyVersion'])
            ->and($decoded->mode)->toBe($input['mode'])
            ->and($decoded->digest)->toBe($input['digest'] ?? null);

        return;
    }

    $code = $codec->decodeProblem($json)->code;
    expect([
        'code' => $code->value,
        'category' => $code->category(),
        'retryable' => $code->retryable(),
        'sameKey' => $code->sameKey(),
        'receipt' => $code->receipt(),
    ])->toBe($expected['ok']);

    $wrong = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $wrong['receipt'] = $code->receipt() === 'stored' ? 'none' : 'stored';
    try {
        $codec->decodeProblem(json_encode($wrong, JSON_THROW_ON_ERROR));
    } catch (ProblemException $exception) {
        expect($exception->problem)->toBe(ProblemCode::ValidationInvalid);

        return;
    }
    throw new LogicException('expected receipt policy refusal: '.$vector['id']);
})->with('ledger contract additions');

it('requires every reallocation command member and validates key and policy syntax', function (): void {
    $path = dirname(__DIR__, 4).'/fixtures/vectors/allocation/reallocate/valid-reversal.json';
    $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $base = json_decode($vector['script'][0]['args']['json_text'], true, 512, JSON_THROW_ON_ERROR);
    $invalid = [];
    foreach (array_keys($base) as $member) {
        $input = $base;
        unset($input[$member]);
        $invalid[] = $input;
    }
    foreach (['refund', 'Refund/1', 'refund/v1', 'refund/1/2', ''] as $policy) {
        $invalid[] = array_replace($base, ['policyVersion' => $policy]);
    }
    $invalid[] = array_replace($base, ['commandKey' => 'not-a-uuid']);

    foreach ($invalid as $input) {
        try {
            (new Codec)->decode(json_encode($input, JSON_THROW_ON_ERROR));
        } catch (ProblemException $exception) {
            expect($exception->problem)->toBe(ProblemCode::ValidationInvalid);

            continue;
        }
        throw new LogicException('expected invalid command refusal');
    }
});
