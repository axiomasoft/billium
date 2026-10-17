<?php

declare(strict_types=1);

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Enums\ProblemCode;

/**
 * @return list<class-string>
 */
function publicTypes(): array
{
    $src = dirname(__DIR__, 2).'/src';
    $types = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Internal/')) {
            continue;
        }
        $name = 'Billium\\Contracts\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($src) + 1, -4));
        if (class_exists($name) || interface_exists($name) || enum_exists($name)) {
            $types[] = $name;
        }
    }

    return $types;
}

it('marks every public type outside Internal with #[Api]', function (): void {
    $unmarked = array_filter(
        publicTypes(),
        fn (string $type): bool => (new ReflectionClass($type))->getAttributes(Api::class) === [],
    );

    expect(publicTypes())->not->toBeEmpty()
        ->and(array_values($unmarked))->toBe([]);
});

it('has no float in a public signature or property', function (): void {
    $floats = [];
    foreach (publicTypes() as $type) {
        $class = new ReflectionClass($type);
        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (str_contains((string) $property->getType(), 'float')) {
                $floats[] = "{$type}::\${$property->getName()}";
            }
        }
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $types = array_map(fn (ReflectionParameter $parameter): string => (string) $parameter->getType(), $method->getParameters());
            $types[] = (string) $method->getReturnType();
            if (str_contains(implode('|', $types), 'float')) {
                $floats[] = "{$type}::{$method->getName()}()";
            }
        }
    }

    expect($floats)->toBe([]);
});

it('does not depend on Laravel or Eloquent', function (): void {
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && preg_match('/\b(Illuminate|Eloquent)\b/', (string) file_get_contents($file->getPathname())) === 1) {
            $found[] = $file->getPathname();
        }
    }

    expect($found)->toBe([]);
});

it('lists the problem codes in the order and with the metadata of the registry', function (): void {
    $registry = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/spec/revisions/2026-10/problem-codes.json'), true, 512, JSON_THROW_ON_ERROR);

    $cases = array_map(fn (ProblemCode $code): array => [
        'code' => $code->value,
        'category' => $code->category(),
        'retryable' => $code->retryable(),
        'sameKey' => $code->sameKey(),
        'receipt' => $code->receipt(),
    ], ProblemCode::cases());
    $expected = array_map(fn (array $entry): array => array_intersect_key($entry, $cases[0]), $registry);

    expect($cases)->toBe($expected);
});
