<?php

declare(strict_types=1);

namespace BilliumConformance;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Illuminate\Contracts\Container\Container;
use stdClass;

/** Dispatch only; the owning module binds its handler after its explicit installation. */
final readonly class P4RuntimeAdapter
{
    public const array OPERATIONS = [
        'usage.ingest', 'usage.aggregate', 'usage.quantity',
        'rating.rate', 'rating.correct',
        'schedule.ensure', 'schedule.generate', 'schedule.revise',
    ];

    public function __construct(private Container $container) {}

    /** @return array<string, mixed> */
    public function run(string $operation, stdClass $arguments): array
    {
        $module = explode('.', $operation, 2)[0];
        $binding = 'billium.conformance.'.$module;
        if (! in_array($operation, self::OPERATIONS, true) || ! $this->container->bound($binding)) {
            throw new ProblemException(ProblemCode::PolicyUnknown, "{$operation} has no installed runtime implementation");
        }
        $handler = $this->container->make($binding);
        if (! is_callable($handler)) {
            throw new ProblemException(ProblemCode::PolicyUnknown, "{$binding} must be a module-owned callable");
        }

        $result = $handler($operation, $arguments);
        if (! is_array($result)) {
            throw new ProblemException(ProblemCode::PolicyUnknown, "{$binding} returned an invalid protocol output");
        }
        $output = [];
        foreach ($result as $key => $value) {
            if (! is_string($key)) {
                throw new ProblemException(ProblemCode::PolicyUnknown, "{$binding} returned a non-string protocol key");
            }
            $output[$key] = $value;
        }

        return $output;
    }
}
