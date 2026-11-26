<?php

declare(strict_types=1);

use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Attributes\Spi;

/**
 * Public types of the contracts package outside Internal, from the public API only (reflection).
 *
 * @return list<ReflectionClass<object>>
 */
function archPublicTypes(): array
{
    $src = dirname(__DIR__, 2).'/src';
    $types = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($src) + 1, -4);
        if (str_starts_with($relative, 'Internal/')) {
            continue;
        }
        $types[] = new ReflectionClass('Billium\\Contracts\\'.str_replace('/', '\\', $relative));
    }

    return $types;
}

arch('contracts do not depend on Laravel or Eloquent')
    ->expect('Billium\Contracts')
    ->not->toUse(['Illuminate', 'Eloquent']);

arch('every Internal type carries #[Internal]')
    ->expect('Billium\Contracts\Internal')
    ->toHaveAttribute(Internal::class);

it('marks every public type outside Internal with #[Api] or #[Spi]', function (): void {
    $missing = [];
    foreach (archPublicTypes() as $class) {
        if ($class->getAttributes(Api::class) === [] && $class->getAttributes(Spi::class) === []) {
            $missing[] = $class->getName();
        }
    }

    expect($missing)->toBe([]);
});

it('keeps float out of public properties, parameters and return types', function (): void {
    $offenders = [];
    foreach (archPublicTypes() as $class) {
        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (str_contains((string) $property->getType(), 'float')) {
                $offenders[] = "{$class->getName()}::\${$property->getName()}";
            }
        }
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $signature = array_map(
                fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
                $method->getParameters(),
            );
            $signature[] = (string) $method->getReturnType();
            if (str_contains(implode('|', $signature), 'float')) {
                $offenders[] = "{$class->getName()}::{$method->getName()}()";
            }
        }
    }

    expect(archPublicTypes())->not->toBeEmpty()
        ->and($offenders)->toBe([]);
});

it('keeps Eloquent types out of public signatures', function (): void {
    $offenders = [];
    foreach (archPublicTypes() as $class) {
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $signature = array_map(
                fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
                $method->getParameters(),
            );
            $signature[] = (string) $method->getReturnType();
            if (preg_match('/Illuminate|Eloquent/', implode('|', $signature)) === 1) {
                $offenders[] = "{$class->getName()}::{$method->getName()}()";
            }
        }
    }

    expect($offenders)->toBe([]);
});
