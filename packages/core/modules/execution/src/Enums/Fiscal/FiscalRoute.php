<?php

declare(strict_types=1);

namespace Billium\Execution\Enums\Fiscal;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum FiscalRoute: string
{
    case PspPassthrough = 'psp_passthrough';
    case FiscalDriver = 'fiscal_driver';
    case ProjectionOnly = 'projection_only';
    case None = 'none';

    public function isDispatchable(): bool
    {
        return $this === self::PspPassthrough || $this === self::FiscalDriver;
    }
}
