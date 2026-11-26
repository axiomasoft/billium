<?php

declare(strict_types=1);

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Errors\BilliumErrorCode;
use Billium\Contracts\Errors\BilliumProblemDefinitions;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

it('maps every ProblemCode 1:1 to a single-dot BilliumErrorCode', function (): void {
    expect(BilliumErrorCode::cases())->toHaveCount(count(ProblemCode::cases()));

    $seen = [];
    foreach (ProblemCode::cases() as $problem) {
        $errorCode = BilliumErrorCode::fromProblemCode($problem);
        expect($errorCode->toProblemCode())->toBe($problem)
            ->and($errorCode->value())->toMatch('/\Abillium\.[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/')
            ->and($seen)->not->toHaveKey($errorCode->value());
        $seen[$errorCode->value()] = true;
    }
});

it('provides complete RFC 9457 definitions for all BilliumErrorCode cases', function (): void {
    $definitions = iterator_to_array((new BilliumProblemDefinitions)->definitions());

    expect($definitions)->toHaveCount(count(BilliumErrorCode::cases()));

    foreach ($definitions as $definition) {
        expect($definition->type)->toBe('urn:packman:problem:'.$definition->code->value())
            ->and($definition->defaultStatus)->toBeGreaterThanOrEqual(400)
            ->and($definition->defaultStatus)->toBeLessThanOrEqual(599)
            ->and(trim($definition->title))->not->toBe('');
    }
});

it('keeps packman problem categories consistent with their HTTP status', function (): void {
    foreach (BilliumErrorCode::cases() as $code) {
        $expected = match ($code->defaultStatus()) {
            401 => ErrorCategory::Authentication,
            403 => ErrorCategory::Authorization,
            404 => ErrorCategory::NotFound,
            410 => in_array($code->category(), [ErrorCategory::Conflict, ErrorCategory::Validation], true)
                ? $code->category()
                : ErrorCategory::NotFound,
            409 => ErrorCategory::Conflict,
            422 => $code->category() === ErrorCategory::Unsupported
                ? ErrorCategory::Unsupported
                : ErrorCategory::Validation,
            429 => ErrorCategory::RateLimit,
            503 => ErrorCategory::Unavailable,
            default => $code->defaultStatus() < 500 ? ErrorCategory::Validation : ErrorCategory::Internal,
        };

        expect($code->value().' '.$code->category()->value)->toBe($code->value().' '.$expected->value);
    }
});

it('builds bounded, sanitized ErrorDescriptor instances from ProblemException', function (): void {
    $exception = new ProblemException(
        ProblemCode::ValidationInvalid,
        "bad\x00input\n".str_repeat('x', 2000),
    );

    expect($exception)->toBeInstanceOf(CodedException::class)
        ->and($exception)->toBeInstanceOf(DescribesError::class);

    $descriptor = $exception->errorDescriptor();

    expect($descriptor->code)->toBe('billium.validation_invalid')
        ->and($descriptor->retryable)->toBeFalse()
        ->and($descriptor->details['problem'])->toBe('billium.validation.invalid')
        ->and(strlen((string) $descriptor->details['detail']))->toBeLessThanOrEqual(1024);
});
