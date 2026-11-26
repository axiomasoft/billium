<?php

declare(strict_types=1);

namespace Billium\Rating\Tests;

use Billium\Rating\Tests\Feature\CorrectionIntegrationSmokeTest;
use PHPUnit\Framework\Attributes\Group;

/** Verifies correction obligations and credit notes with issued originals. */
#[Group('pg')]
final class LateUsageIssuedCorrectionTest extends CorrectionIntegrationSmokeTest {}
