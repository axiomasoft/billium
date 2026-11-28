<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Migration;

use Billium\Platform\Data\Migration\ExecutionRoute;
use Billium\Platform\Data\Migration\IntentBinding;
use Billium\Platform\Data\Migration\RoutingScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** The host calls reserve before sending a command and uses the stored binding for all recovery. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface AuthorityRouter
{
    /**
     * Privileged bootstrap from a complete, sealed inventory, including retained original bindings and tombstones.
     *
     * @param  list<IntentBinding>  $existing
     */
    public function initialize(RoutingScope $scope, ExecutionRoute $route, array $existing): void;

    /** Privileged compare-and-set for the next routing epoch. */
    public function advance(RoutingScope $scope, ExecutionRoute $expected, ExecutionRoute $next): void;

    public function current(RoutingScope $scope): ExecutionRoute;

    public function lookup(RoutingScope $scope, string $operationRef): ?IntentBinding;

    public function reserve(RoutingScope $scope, IntentBinding $intent): IntentBinding;
}
