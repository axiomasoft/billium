<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Operations;

use BackedEnum;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\CallerContext;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Enums\FinancialMode;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Billium\Schedule\Internal\Canonical;
use Billium\Schedule\Internal\OccurrenceTime;
use DateTimeImmutable;

final readonly class ScheduleOperations
{
    public function __construct(private CallerContext $callers) {}

    public function spec(string $kind, string $seller, string $environment, ?string $key, mixed $payload): OperationSpec
    {
        $caller = $this->callers->current();
        if ($caller->sellerRef !== $seller || $caller->environment !== $environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.schedule.scope_denied');
        }

        return new OperationSpec(commandKind: $kind, sellerRef: $seller, environment: $environment,
            mode: FinancialMode::Active, operationClass: ModuleOperationClass::NewIntent,
            modules: ['platform', 'rating', 'schedule'], commandKey: $key,
            digest: $key === null ? null : Canonical::digest(self::payload($payload)), commitKind: $kind);
    }

    public static function payload(mixed $value): mixed
    {
        if ($value instanceof DateTimeImmutable) {
            return OccurrenceTime::format($value);
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof ScheduleRevisionTerms) {
            return $value->toArray();
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map(self::payload(...), $value);
        }

        return $value;
    }
}
