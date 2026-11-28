<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Packman\Attributes\Api;
use RuntimeException;

/** The caller must recover or rebootstrap with current evidence. */
#[Api(since: '1.0')]
final class HandoffRecoveryRequired extends RuntimeException {}
