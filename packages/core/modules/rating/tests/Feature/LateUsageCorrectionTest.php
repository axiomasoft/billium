<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use PHPUnit\Framework\Attributes\Group;

/** Full original-period correction and retained-history scenarios. */
#[Group('pg')]
final class LateUsageCorrectionTest extends CorrectionIntegrationSmokeTest {}
