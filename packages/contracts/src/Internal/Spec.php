<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal;

use LogicException;
use Packman\Attributes\Internal;

/**
 * The spec revision data generated into resources/ by scripts/spec/export-php.py.
 */
#[Internal]
final class Spec
{
    /** @var array<string, mixed>|null */
    private static ?array $schemas = null;

    /** @var array<string, array{schemaId: string, variants: list<array{class: string, digestMember: string, verifyDigest: bool, requires?: string}>}>|null */
    private static ?array $envelopes = null;

    /**
     * Every schema of the revision by `$id`.
     *
     * @return array<string, mixed>
     */
    public static function schemas(): array
    {
        return self::$schemas ??= self::read('schemas.json');
    }

    /**
     * Envelope registry by schema name: the schema `$id` and the ordered variants that pick a class.
     *
     * @return array<string, array{schemaId: string, variants: list<array{class: string, digestMember: string, verifyDigest: bool, requires?: string}>}>
     */
    public static function envelopes(): array
    {
        if (self::$envelopes === null) {
            /** @var array<string, array{schemaId: string, variants: list<array{class: string, digestMember: string, verifyDigest: bool, requires?: string}>}> $envelopes */
            $envelopes = self::read('envelopes.json');
            self::$envelopes = $envelopes;
        }

        return self::$envelopes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $file): array
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/resources/spec/'.$file);
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
