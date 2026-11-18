<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\SimplifyBoolIdenticalTrueRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->disableParallel();
    $rectorConfig->paths([
        __DIR__.'/packages/contracts/src',
        __DIR__.'/packages/core/src',
        __DIR__.'/packages/core/modules/platform/src',
        __DIR__.'/packages/core/modules/ledger/src',
        __DIR__.'/packages/core/modules/usage/src',
        __DIR__.'/packages/core/modules/rating/src',
        __DIR__.'/packages/core/modules/schedule/src',
        __DIR__.'/packages/core/modules/execution/src',
        __DIR__.'/packages/testkit/src',
    ]);
    $rectorConfig->importNames(importDocBlockNames: false);
    $rectorConfig->removeUnusedImports();

    PackmanRectorSet::configure($rectorConfig, [
        __DIR__.'/packages/core/database/migrations',
        __DIR__.'/packages/contracts/src/Data/Envelopes',
        StringClassNameToClassConstantRector::class => [
            __DIR__.'/packages/core/src/aliases.php',
        ],
        ReadOnlyClassRector::class,
        ReadOnlyPropertyRector::class,
        ReadOnlyAnonymousClassRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        ExplicitNullableObjectInstanceRector::class,
        RemoveEmptyClassMethodRector::class,
        RemoveUnusedPublicMethodParameterRector::class,
        NarrowObjectReturnTypeRector::class,
        ThrowWithPreviousExceptionRector::class,
        SimplifyBoolIdenticalTrueRector::class,
    ]);
};
