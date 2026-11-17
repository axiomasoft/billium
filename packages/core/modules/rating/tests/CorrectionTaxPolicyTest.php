<?php

declare(strict_types=1);

namespace Billium\Rating\Tests;

use Billium\Rating\Tests\Feature\CorrectionIntegrationSmokeTest;
use PHPUnit\Framework\Attributes\Group;

/** Includes policy version, selected rate, missing and stale evidence scenarios. */
#[Group('pg')]
final class CorrectionTaxPolicyTest extends CorrectionIntegrationSmokeTest {}
