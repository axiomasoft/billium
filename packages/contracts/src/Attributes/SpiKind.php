<?php

declare(strict_types=1);

namespace Billium\Contracts\Attributes;

use Packman\Attributes\Api as PackmanApi;

/**
 * Who stands on which side of a service-provider interface.
 *
 * @deprecated Use {@see \Packman\Attributes\SpiKind} instead.
 */
#[PackmanApi(since: '1.0')]
enum SpiKind
{
    /** Consumers call the interface; the package owns the implementation. */
    case Call;

    /** Consumers implement the interface; the package calls it. */
    case Implement;
}
