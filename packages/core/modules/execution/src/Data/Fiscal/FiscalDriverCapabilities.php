<?php

declare(strict_types=1);

namespace Billium\Execution\Data\Fiscal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FiscalDriverCapabilities
{
    /**
     * @param  list<FiscalRoute>  $supportedRoutes
     * @param  list<FiscalCallbackTrustStrategy>  $supportedCallbackTrustStrategies
     */
    public function __construct(
        public string $driverId,
        public string $contractVersion,
        public array $supportedRoutes,
        public array $supportedCallbackTrustStrategies,
        public bool $supportsLookup = true,
        public bool $supportsCorrection = true,
    ) {
        if ($driverId === '' || $contractVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'driverId and contractVersion must not be empty');
        }
        if ($supportedRoutes === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'FiscalDriver must support at least one dispatchable route');
        }
        foreach ($supportedRoutes as $route) {
            if (! $route->isDispatchable()) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'FiscalDriverCapabilities may only advertise psp_passthrough or fiscal_driver');
            }
        }
    }

    public function supportsRoute(FiscalRoute $route): bool
    {
        return in_array($route, $this->supportedRoutes, true);
    }

    public function supportsTrustStrategy(FiscalCallbackTrustStrategy $strategy): bool
    {
        return in_array($strategy, $this->supportedCallbackTrustStrategies, true);
    }
}
