<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Runner;

use Packman\Attributes\Api;

/** Which authenticated scope LockSet adds to a row-lock predicate. */
#[Api(since: '1.0')]
enum LockScope: string
{
    /** seller_ref and environment of the authenticated caller (domain rows). */
    case Seller = 'seller';
    /** installation_ref of the authenticated caller (installation-wide rows). */
    case Installation = 'installation';
}
