<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal;

use Billium\Contracts\Contracts\SpecRevision;
use LogicException;
use Packman\Attributes\Internal;

/**
 * The spec revision data generated into resources/ by scripts/spec/export-php.py.
 */
#[Internal]
final class Spec
{
    /** @var array<string, array<string, mixed>> */
    private static array $schemas = [];

    /** @var array<string, array<string, array{schemaId: string, variants: list<array{class: string, digestMember: string, verifyDigest: bool, requires?: string}>}>> */
    private static array $envelopes = [];

    /**
     * Every schema of the revision by `$id`.
     *
     * @return array<string, mixed>
     */
    public static function schemas(string $revision = SpecRevision::CURRENT): array
    {
        return self::$schemas[$revision] ??= self::read('schemas.json', $revision);
    }

    /**
     * Envelope registry by schema name: the schema `$id` and the ordered variants that pick a class.
     *
     * @return array<string, array{schemaId: string, variants: list<array{class: string, digestMember: string, verifyDigest: bool, requires?: string}>}>
     */
    public static function envelopes(string $revision = SpecRevision::CURRENT): array
    {
        if (! isset(self::$envelopes[$revision])) {
            /** @var array<string, array{schemaId: string, variants: list<array{class: string, digestMember: string, verifyDigest: bool, requires?: string}>}> $envelopes */
            $envelopes = self::read('envelopes.json', $revision);
            self::$envelopes[$revision] = $envelopes;
        }

        return self::$envelopes[$revision];
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $file, string $revision): array
    {
        $baseDir = dirname(__DIR__, 2).'/resources/spec';
        $revisionPath = "{$baseDir}/{$revision}/{$file}";
        $path = is_file($revisionPath) ? $revisionPath : "{$baseDir}/{$file}";

        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new LogicException("spec resource {$file} is missing");
        }

        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new LogicException("spec resource {$file} is not an object");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
