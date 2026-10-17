<?php

declare(strict_types=1);

use Billium\Contracts\Data\Envelope;
use Billium\Contracts\Enums\ProblemCode;

/**
 * @return list<string>
 */
function identitiesSnapshot(string $kind): array
{
    $path = dirname(__DIR__, 4)."/spec/identities/snapshots/{$kind}.json";

    /** @var list<string> */
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

it('matches the problem-code snapshot', function (): void {
    $codes = array_map(fn (ProblemCode $code): string => $code->value, ProblemCode::cases());
    sort($codes);

    expect($codes)->toBe(identitiesSnapshot('problem-code'));
});

it('matches the envelope snapshot', function (): void {
    $dir = dirname(__DIR__, 2).'/src/Data/Envelopes';
    $schemas = [];
    foreach (glob($dir.'/*.php') ?: [] as $file) {
        $class = 'Billium\\Contracts\\Data\\Envelopes\\'.basename($file, '.php');
        expect(is_subclass_of($class, Envelope::class))->toBeTrue();
        $schemas[] = (new ReflectionClass($class))->getReflectionConstant('SCHEMA')?->getValue();
    }
    $schemas = array_values(array_unique($schemas));
    sort($schemas);

    expect($schemas)->toBe(identitiesSnapshot('envelope'));
});

it('has the spec revision of the package in the revision snapshot', function (): void {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect(identitiesSnapshot('spec-revision'))->toBe([$manifest['extra']['billium']['spec_rev']]);
});
