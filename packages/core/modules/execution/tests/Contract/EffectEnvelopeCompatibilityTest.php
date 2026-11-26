<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Envelopes\VoidAuthorization;
use Billium\Contracts\Exceptions\ProblemException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EffectEnvelopeCompatibilityTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function envelopeVectors(): iterable
    {
        $root = dirname(__DIR__, 6);
        foreach (['execute', 'effects', 'p5-contracts'] as $directory) {
            $files = glob($root.'/fixtures/vectors/payment/'.$directory.'/*.json');
            if ($files === false || $files === []) {
                throw new \RuntimeException('Missing contract fixtures: '.$directory);
            }
            foreach ($files as $file) {
                $vector = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                if (count($vector['script']) === 1 && $vector['script'][0]['op'] === 'envelope.decode') {
                    yield $vector['id'] => [$vector['script'][0]['args']['json_text'], $vector['expect'][0]];
                }
            }
        }
    }

    /** @param array<string, mixed> $expect */
    #[DataProvider('envelopeVectors')]
    public function test_legacy_and_new_envelope_fixtures_keep_their_exact_result(string $json, array $expect): void
    {
        $codec = new Codec;
        try {
            $envelope = $codec->decode($json);
        } catch (ProblemException $exception) {
            self::assertArrayHasKey('error', $expect);
            self::assertSame($expect['error']['code'], $exception->problem->value);

            return;
        }
        self::assertArrayHasKey('ok', $expect);
        self::assertSame($expect['ok']['schema'], $envelope->schema);
        self::assertSame($expect['ok']['digest'], $codec->digestOf($envelope));
        self::assertSame($codec->encode($envelope), $codec->encode($codec->decode($codec->encode($envelope))));
    }

    public function test_provider_authorization_commands_have_separate_types_from_local_cancellation(): void
    {
        $codec = new Codec;
        $root = dirname(__DIR__, 6);
        foreach ([
            'authorize-valid' => AuthorizePayment::class,
            'capture-valid' => CapturePayment::class,
            'void-valid' => VoidAuthorization::class,
        ] as $fixture => $class) {
            $vector = json_decode(file_get_contents($root.'/fixtures/vectors/payment/p5-contracts/'.$fixture.'.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertInstanceOf($class, $codec->decode($vector['script'][0]['args']['json_text']));
        }
    }
}
