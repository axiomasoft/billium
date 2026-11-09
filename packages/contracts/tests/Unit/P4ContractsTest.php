<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Data\Envelopes\PriceVersion;
use Billium\Contracts\Data\Envelopes\UsageFact;
use Billium\Contracts\Exceptions\ProblemException;

dataset('P4 wire vectors', function (): array {
    $root = dirname(__DIR__, 4).'/fixtures/vectors/';
    $paths = [];
    foreach (['usage/contracts', 'usage/runtime', 'rating/contracts', 'rating/runtime'] as $set) {
        foreach (glob($root.$set.'/*.json') ?: [] as $path) {
            $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if ($vector['script'][0]['op'] === 'envelope.decode') {
                $paths[$vector['id']] = [$vector];
            }
        }
    }

    foreach (['obligation/prepare', 'occurrence/ensure', 'usage/fact'] as $set) {
        $vector = json_decode((string) file_get_contents($root.$set.'/schema-v3.json'), true, 512, JSON_THROW_ON_ERROR);
        $paths[$vector['id']] = [$vector];
    }

    return $paths;
});

it('validates P4 contract fixtures and roundtrips exact quantities and periods', function (array $vector): void {
    $codec = new Codec;
    $json = $vector['script'][0]['args']['json_text'];
    $expected = $vector['expect'][0];
    if (isset($expected['error'])) {
        try {
            $codec->decode($json);
        } catch (ProblemException $exception) {
            expect($exception->problem->value)->toBe($expected['error']['code']);

            return;
        }
        throw new LogicException('accepted negative fixture '.$vector['id']);
    }
    $decoded = $codec->decode($json);
    expect($decoded->schema)->toBe($expected['ok']['schema'])
        ->and($codec->encode($decoded))->toBe($codec->canonicalize($json));
    $expectedDigest = $expected['ok']['digest'];
    if (is_array($expectedDigest)) {
        $expectedDigest = rtrim((string) file_get_contents(dirname(__DIR__, 4).'/fixtures/golden/'.$expectedDigest['golden']), "\n");
    }
    if (isset($vector['control'])) {
        expect($expectedDigest)->toBeString()
            ->and($codec->digestOf($decoded))->not->toBe($expectedDigest);

        return;
    }
    expect($codec->digestOf($decoded))->toBe($expectedDigest);
    if ($decoded->schema === 'usage.metric.v1') {
        expect($decoded)->toBeInstanceOf(MetricVersion::class);
    }
    if ($decoded->schema === 'rating.price.v1') {
        expect($decoded)->toBeInstanceOf(PriceVersion::class);
    }
})->with('P4 wire vectors');

it('retains the historical usage envelope class and digest unchanged', function (): void {
    $path = dirname(__DIR__, 4).'/fixtures/vectors/usage/fact/valid-additive.json';
    $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codec = new Codec;
    $fact = $codec->decode($vector['script'][0]['args']['json_text']);
    expect($fact)->toBeInstanceOf(UsageFact::class)
        ->and($codec->digestOf($fact))->toBe($vector['expect'][0]['ok']['digest']);
});
