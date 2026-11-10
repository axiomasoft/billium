<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Exceptions\ProblemException;
use PHPUnit\Framework\TestCase;

final class EffectEnvelopeCompatibilityTest extends TestCase
{
    public function test_new_effect_commands_and_capabilities_round_trip_with_negative_controls(): void
    {
        $root = dirname(__DIR__, 6).'/fixtures/vectors/payment/';
        $files = array_merge(glob($root.'effects/*.json') ?: [], glob($root.'capabilities/*.json') ?: []);
        self::assertGreaterThan(30, count($files));
        $codec = new Codec;
        foreach ($files as $file) {
            $vector = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $json = $vector['script'][0]['args']['json_text'];
            $expected = $vector['expect'][0];
            try {
                $envelope = $codec->decode($json);
                self::assertArrayNotHasKey('error', $expected, $vector['id']);
                self::assertSame($expected['ok']['schema'], $envelope->schema);
                self::assertSame($expected['ok']['digest'], $codec->digestOf($envelope));
                self::assertSame($codec->canonicalize($json), $codec->encode($envelope));
            } catch (ProblemException $error) {
                self::assertSame($expected['error']['code'] ?? 'unexpected refusal', $error->problem->value, $vector['id']);
            }
        }
    }

    public function test_pinned_p34_payload_and_old_pilot_enums_and_envelopes_are_preserved(): void
    {
        $root = dirname(__DIR__, 6);
        self::assertSame('1b0e6b4b2213f2e57cd5d25df4d9560fa676c4e8ca976f72d833756b2ec0cfa4', hash_file('sha256', $root.'/spec/revisions/2026-10/schema/payment/effect-outcome.v1.json'));
        $defs = json_decode((string) file_get_contents($root.'/spec/revisions/2026-10/schema/common/defs.json'), true, 512, JSON_THROW_ON_ERROR)['$defs'];
        self::assertSame(['charge', 'refund'], $defs['operationKind']['enum']);
        self::assertNotContains('payment.effect.outcome.v1', $defs['eventType']['enum']);
        $files = array_merge(
            glob($root.'/fixtures/vectors/payment/execute/*.json') ?: [],
            glob($root.'/fixtures/vectors/payment/refund/*.json') ?: [],
            glob($root.'/fixtures/vectors/payment/cancel/*.json') ?: [],
            glob($root.'/fixtures/vectors/payment/find/*.json') ?: [],
            glob($root.'/fixtures/vectors/payment/outcome/*.json') ?: [],
        );
        self::assertNotEmpty($files);
        $codec = new Codec;
        foreach ($files as $file) {
            $vector = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($vector['script'] as $index => $step) {
                if ($step['op'] !== 'envelope.decode') {
                    continue;
                }
                $expected = $vector['expect'][$index];
                try {
                    $value = $codec->decode($step['args']['json_text']);
                    self::assertArrayNotHasKey('error', $expected, $vector['id']);
                    self::assertSame($expected['ok']['digest'], $codec->digestOf($value));
                } catch (ProblemException $error) {
                    self::assertSame($expected['error']['code'] ?? 'unexpected refusal', $error->problem->value, $vector['id']);
                }
            }
        }
    }
}
