<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use PHPUnit\Framework\Attributes\Group;

/**
 * A refund is a distinct child of a captured original, addressed to the selected scope and in the original's currency.
 * Refusals happen before any reservation, so they never consume refundable capacity.
 */
#[Group('pg')]
final class RefundAdmissionGuardTest extends ExecutionTestCase
{
    public function test_misaddressed_cross_currency_and_self_refunds_are_refused_without_reserving_capacity(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);
            $commands = $app->make(RefundCommands::class);

            self::refused(ProblemCode::AuthDenied, fn () => $commands->admit($scope, Refunds::refund('other-seller', 10, 2, ['sellerRef' => 'seller-b'])));
            self::refused(ProblemCode::AuthDenied, fn () => $commands->admit($scope, Refunds::refund('other-environment', 10, 3, ['environment' => 'live'])));
            self::refused(ProblemCode::ValidationInvalid, fn () => $commands->admit($scope, Refunds::refund('dollars', 10, 4, ['money' => ['amount' => '0.10', 'currency' => 'USD']])));
            self::refused(ProblemCode::ValidationInvalid, fn () => $commands->admit($scope, Refunds::refund('root-a', 10, 5)));
            self::assertSame(0, $this->rows('billium_execution_reservation'));
            self::assertSame(0, $this->rows('billium_execution_child_identity'));

            // The whole captured amount is still refundable after the refusals.
            $commands->admit($scope, Refunds::refund('full', 100, 6))->orThrow();
            $view = $app->make(ExecutionQueries::class)->status($scope, 'full');
            self::assertSame(100, $view->requested->minor);
            self::assertEquals((object) ['amount' => '0.00', 'currency' => 'RUB'], $view->capacity->refundAvailable);
            self::assertSame(ProblemCode::RefundCapacityExceeded, $commands->admit($scope, Refunds::refund('one-more', 1, 7))->problem);
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    /** @param callable(): StoredOutcome $admit */
    private static function refused(ProblemCode $expected, callable $admit): void
    {
        try {
            $outcome = $admit();
        } catch (ProblemException $failure) {
            self::assertSame($expected, $failure->problem);

            return;
        }
        self::assertSame($expected, $outcome->problem);
    }
}
