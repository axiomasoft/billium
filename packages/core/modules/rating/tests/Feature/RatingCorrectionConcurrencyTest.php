<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use PHPUnit\Framework\Attributes\Group;

/** Shared correction scenarios include competing five-process roots. */
#[Group('pg')]
final class RatingCorrectionConcurrencyTest extends CorrectionIntegrationSmokeTest {}
