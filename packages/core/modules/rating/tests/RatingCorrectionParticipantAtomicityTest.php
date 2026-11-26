<?php

declare(strict_types=1);

namespace Billium\Rating\Tests;

use Billium\Rating\Tests\Feature\CorrectionIntegrationSmokeTest;
use PHPUnit\Framework\Attributes\Group;

/** Includes rollback proofs over both ledger participants and correction-owned boundaries. */
#[Group('pg')]
final class RatingCorrectionParticipantAtomicityTest extends CorrectionIntegrationSmokeTest {}
