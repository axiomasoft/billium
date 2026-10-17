<?php

declare(strict_types=1);

namespace Billium\Contracts\Attributes;

/**
 * Who stands on which side of a service-provider interface.
 */
#[Api]
enum SpiKind
{
    /** Consumers call the interface; the package owns the implementation. */
    case Call;

    /** Consumers implement the interface; the package calls it. */
    case Implement;
}
