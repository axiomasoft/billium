<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Packman\Attributes\Api;
use RuntimeException;

/** D54/D63: the caller must recover or rebootstrap with current evidence. */
#[Api(since: '1.0')]
final class HandoffRecoveryRequired extends RuntimeException {}
