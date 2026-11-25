<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\AuthorizationRecord;
use Billium\Execution\Data\CapabilityDetails;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Authorization\AuthorizationPolicy;
use Billium\Execution\Tests\Feature\ExecutionSchemaTest;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Capture and release refuse a closed authorization, a held original and an account without capture. */
final class AuthorizationPolicyTest extends TestCase
{
    public function test_a_fully_captured_authorization_is_closed(): void
    {
        $now = new DateTimeImmutable('2026-11-25T10:00:00Z');
        AuthorizationPolicy::assertOpen(self::authorization('authorized'), $now);
        $this->expectExceptionObject(new ProblemException(ProblemCode::AuthorizationCapacityExceeded, 'Authorization is closed to further capture or release'));
        AuthorizationPolicy::assertOpen(self::authorization('captured'), $now);
    }

    public function test_a_financial_hold_blocks_capture(): void
    {
        $capability = ExecutionSchemaTest::capabilityFixture();
        AuthorizationPolicy::assertAmount(EffectKind::Capture, Money::parse('1.00', 'RUB'), $capability, self::capacity(false));
        $this->expectExceptionObject(new ProblemException(ProblemCode::OperationFinancialHold, 'Original payment has an active financial hold'));
        AuthorizationPolicy::assertAmount(EffectKind::Capture, Money::parse('1.00', 'RUB'), $capability, self::capacity(true));
    }

    public function test_an_account_without_capture_refuses_capture(): void
    {
        $fixture = ExecutionSchemaTest::capabilityFixture();
        $details = new CapabilityDetails(...array_replace(get_object_vars($fixture->details), ['captureMode' => CaptureMode::None]));
        $capability = new CapabilitySnapshot(...array_replace(get_object_vars($fixture), ['details' => $details, 'partialCapture' => false,
            'supportedEffects' => [EffectKind::Charge, EffectKind::Authorize, EffectKind::Refund, EffectKind::VoidAuthorization]]));
        $this->expectExceptionObject(new ProblemException(ProblemCode::CapabilityUnsupported, 'Requested capture is outside the original account capture mode'));
        AuthorizationPolicy::assertAmount(EffectKind::Capture, Money::parse('1.00', 'RUB'), $capability, self::capacity(false));
    }

    private static function authorization(string $state): AuthorizationRecord
    {
        return new AuthorizationRecord('authorization', 'root', Money::parse('10.00', 'RUB'), Money::parse('0.00', 'RUB'), null, null, $state, 1);
    }

    private static function capacity(bool $hold): CapacitySnapshot
    {
        $zero = Money::parse('0.00', 'RUB');

        return new CapacitySnapshot('root', 1, Money::parse('10.00', 'RUB'), $zero, $zero, $zero, $zero, $zero, $zero, $hold);
    }
}
