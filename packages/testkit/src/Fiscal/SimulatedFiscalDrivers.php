<?php

declare(strict_types=1);

namespace Billium\Testkit\Fiscal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\Fiscal\FiscalDriver;
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
use LogicException;
use Packman\Attributes\Api;

/**
 * Exact-version fiscal driver registry for tests. Refuses unknown versions and never substitutes latest.
 */
#[Api(since: '1.0')]
final class SimulatedFiscalDrivers implements FiscalDrivers
{
    /** @var array<string, FiscalDriver> */
    private array $drivers = [];

    /** @param list<FiscalDriver> $initial */
    public function __construct(array $initial = [])
    {
        foreach ($initial as $driver) {
            $this->register($driver);
        }
    }

    public function register(FiscalDriver $driver): void
    {
        $key = self::key($driver->driverId(), $driver->contractVersion());
        if (isset($this->drivers[$key])) {
            throw new LogicException("Duplicate fiscal driver registration for {$key}");
        }
        $this->drivers[$key] = $driver;
    }

    public function resolve(string $driverId, string $contractVersion): FiscalDriver
    {
        $key = self::key($driverId, $contractVersion);
        if (! isset($this->drivers[$key])) {
            throw new ProblemException(
                ProblemCode::CapabilityUnsupported,
                "Exact fiscal driver contract {$key} is not registered; latest version substitution is forbidden",
            );
        }

        return $this->drivers[$key];
    }

    public function supports(string $driverId, string $contractVersion): bool
    {
        return isset($this->drivers[self::key($driverId, $contractVersion)]);
    }

    private static function key(string $driverId, string $contractVersion): string
    {
        return $driverId.'@'.$contractVersion;
    }
}
