<?php

declare(strict_types=1);

namespace Billium\Core\Exceptions;

use Billium\Contracts\Attributes\Api;
use RuntimeException;

/**
 * The row set re-read under locks differs from the pre-read plan. The runner rolls back and retries the whole root
 * database work within its budget; the previous TransactionContext is invalid (invariant-model).
 */
#[Api]
final class LockPlanChanged extends RuntimeException {}
