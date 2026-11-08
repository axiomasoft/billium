<?php

declare(strict_types=1);

namespace Billium\Core\Exceptions;

use Billium\Contracts\Attributes\Api;
use LogicException;

/** A lock request below an already acquired (rank, segment, key) or an upgrade of a held shared lock (D27). */
#[Api]
final class LockOrderViolation extends LogicException {}
