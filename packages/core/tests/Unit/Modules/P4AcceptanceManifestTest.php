<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Modules;

use Billium\Contracts\Contracts\Codec;
use PHPUnit\Framework\TestCase;

final class P4AcceptanceManifestTest extends TestCase
{
    private const array RequiredCases = ['ACC-CON-11', 'ACC-CON-12', 'MG-08', 'SB-02', 'SB-09', 'EC-11', 'COV-AC-520',
        'P4-calendar', 'P4-tiers', 'P4-commitment', 'P4-tax', 'P4-gauge', 'P4-distinct', 'P4-finality', 'P4-composition', 'P4-source-conflict'];

    private const array RequiredFeatures = ['trials_free_tiers', 'seat_quantity', 'discounts_coupons', 'prepaid_postpaid',
        'package_block_tiers', 'minimum_maximum', 'pause_resume_backdate', 'calendar', 'extension_gate'];

    public function test_every_required_subject_has_concrete_fixture_and_test_traceability(): void
    {
        $manifest = $this->load('docs/architecture/p4-acceptance.json');
        self::assertSame([], $this->gaps($manifest));
        foreach (['cases', 'feature_dispositions'] as $section) {
            self::assertSame(count($manifest[$section]), count(array_unique(array_column($manifest[$section], 'id'))));
            foreach ($manifest[$section] as $row) {
                self::assertNotEmpty($row['owner']);
                foreach ($row['fixtures'] ?? [$row['fixture']] as $path) {
                    self::assertFileExists($this->root().'/'.$path);
                }
                self::assertNotEmpty($row['tests']);
                foreach ($row['tests'] as $reference) {
                    [$path, $method] = array_pad(explode('::', $reference), 2, null);
                    self::assertFileExists($this->root().'/'.$path);
                    $source = file_get_contents($this->root().'/'.$path);
                    self::assertNotFalse($source);
                    if ($method !== null) {
                        self::assertMatchesRegularExpression('/function\s+'.preg_quote($method, '/').'\s*\(/', $source, $reference);
                    }
                }
            }
        }
        foreach ($manifest['feature_dispositions'] as $feature) {
            self::assertNotEmpty($feature['disposition']);
            self::assertSame([], array_values(array_diff($feature['cases'], array_column($manifest['cases'], 'id'))));
        }
    }

    public function test_a_missing_subject_or_feature_disposition_is_red(): void
    {
        $manifest = $this->load('docs/architecture/p4-acceptance.json');
        $manifest['cases'] = array_values(array_filter($manifest['cases'], static fn (array $row): bool => $row['id'] !== 'EC-11'));
        $manifest['feature_dispositions'] = array_values(array_filter($manifest['feature_dispositions'], static fn (array $row): bool => $row['id'] !== 'package_block_tiers'));
        self::assertSame(['EC-11', 'package_block_tiers'], $this->gaps($manifest));
    }

    public function test_advance_fixed_and_approved_quantity_are_explicit_codec_inputs(): void
    {
        $fixture = $this->load('fixtures/p4/advance-codecs.json');
        $codec = new Codec;
        foreach ($fixture['cases'] as $case) {
            $base = $this->load($case['base']);
            $input = json_decode($base['script'][0]['args']['json_text'], flags: JSON_THROW_ON_ERROR);
            $input->chargeTiming = $case['chargeTiming'];
            $input->quantityBasis = $case['quantityBasis'];
            $input->digest = $codec->digest($input);
            $decoded = $codec->decode($codec->canonicalize($input));
            self::assertSame('rating.price.v1', $decoded->schema);
            self::assertSame($input->digest, $codec->digestOf($decoded));
        }
    }

    /** @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function gaps(array $manifest): array
    {
        return array_values(array_merge(array_diff(self::RequiredCases, array_column($manifest['cases'], 'id')),
            array_diff(self::RequiredFeatures, array_column($manifest['feature_dispositions'], 'id'))));
    }

    /** @return array<string, mixed> */
    private function load(string $path): array
    {
        return json_decode(file_get_contents($this->root().'/'.$path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function root(): string
    {
        return dirname(__DIR__, 5);
    }
}
