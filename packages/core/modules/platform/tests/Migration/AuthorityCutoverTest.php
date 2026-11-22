<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Migration;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Contracts\Migration\AuthorityRouter;
use Billium\Platform\Data\Migration\ExecutionRoute;
use Billium\Platform\Data\Migration\IntentBinding;
use Billium\Platform\Data\Migration\RoutingScope;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('P9.4')]
final class AuthorityCutoverTest extends PlatformTestCase
{
    public function test_mgate_01_epoch_barrier_keeps_original_owner_and_ct_20_refunds(): void
    {
        $scope = new RoutingScope('inst', 'test', 'seller', 'consumer');
        $old = new ExecutionRoute('embedded-owner', 4);
        $new = new ExecutionRoute('server-owner', 5);
        $charge = self::intent('old', 'cmd-old', $old);
        $router = $this->router();
        $router->initialize($scope, $old, [$charge]);

        $candidate = self::intent('new', 'cmd-new', $new);
        self::assertConflict(fn () => $router->reserve($scope, $candidate));
        $router->advance($scope, $old, $new);

        // Separate connection and service instance, as after a process restart.
        $this->application()->make(DatabaseManager::class)->purge('billium-routing');
        $router = $this->application()->make(AuthorityRouter::class);
        $router->advance($scope, $old, $new);
        self::assertConflict(fn () => $router->advance($scope, new ExecutionRoute('wrong-predecessor', 4), $new));
        self::assertEquals($new, $router->current($scope));
        self::assertEquals($candidate, $router->reserve($scope, $candidate));
        self::assertEquals($charge, $router->lookup($scope, 'op-old'));
        self::assertEquals($charge, $router->reserve($scope, $charge));
        self::assertConflict(fn () => $router->reserve($scope, self::intent('old', 'replacement-key', $new)));
        self::assertConflict(fn () => $router->reserve($scope, self::intent('another', 'cmd-old', $new)));
        self::assertConflict(fn () => $router->reserve($scope, self::intent('stale', 'cmd-stale', $old)));
        self::assertConflict(fn () => $router->reserve($scope, self::intent('old', 'cmd-old', $old, digest: 'b')));

        $refund = self::intent('refund', 'cmd-refund', $old, originalCharge: 'op-old');
        self::assertConflict(fn () => $router->reserve($scope, self::intent('refund', 'cmd-refund', $new, originalCharge: 'op-old')));
        self::assertConflict(fn () => $router->reserve($scope, self::intent('refund', 'cmd-refund', $old, originalCharge: 'op-new')));
        self::assertEquals($refund, $router->reserve($scope, $refund));
        self::assertEquals($refund, $router->lookup($scope, 'op-refund'));
        self::assertNull($router->lookup(new RoutingScope('inst', 'prod', 'seller', 'consumer'), 'op-old'));

        $this->expectException(QueryException::class);
        $this->owner()->update("UPDATE public.billium_intent_binding SET executor_ref = 'other' WHERE operation_ref = 'op-old'");
    }

    public function test_failed_seed_commits_neither_registry_nor_barrier(): void
    {
        $scope = new RoutingScope('inst', 'test', 'seller', 'consumer');
        $router = $this->router();
        $route = new ExecutionRoute('embedded-owner', 1);
        $charge = self::intent('old', 'cmd-old', $route);
        $badRefund = self::intent('refund', 'refund', $route, originalCharge: 'unknown');
        self::assertConflict(fn () => $router->initialize($scope, $route, [$charge, $badRefund]));
        self::assertConflict(fn () => $router->current($scope));
        self::assertNull($router->lookup($scope, $charge->operationRef));
        $router->initialize($scope, $route, [$charge]);
        self::assertEquals($charge, $router->lookup($scope, $charge->operationRef));
    }

    public function test_reservation_replay_waits_for_the_same_scope_barrier_lock(): void
    {
        $scope = new RoutingScope('inst', 'test', 'seller', 'consumer');
        $route = new ExecutionRoute('embedded-owner', 1);
        $charge = self::intent('old', 'cmd-old', $route);
        $router = $this->router();
        $router->initialize($scope, $route, [$charge]);
        $writer = self::connection('billium-routing');
        $owner = $this->owner();

        $writer->statement("SET lock_timeout = '100ms'");
        $owner->beginTransaction();
        try {
            $owner->selectOne('SELECT scope_key FROM public.billium_authority_route WHERE scope_key = ? FOR UPDATE', [$scope->key()]);
            try {
                $router->reserve($scope, $charge);
                self::fail('Reservation bypassed the routing barrier lock');
            } catch (QueryException $error) {
                self::assertSame('55P03', $error->errorInfo[0]);
            }
        } finally {
            $owner->rollBack();
            $writer->statement('RESET lock_timeout');
        }

        self::assertEquals($charge, $router->reserve($scope, $charge));
    }

    private function router(): AuthorityRouter
    {
        $this->application()->make(Repository::class)->set('database.connections.billium-routing', $this->owner()->getConfig());

        return $this->application()->make(AuthorityRouter::class);
    }

    private static function intent(string $ref, string $key, ExecutionRoute $route, ?string $originalCharge = null, string $digest = 'a'): IntentBinding
    {
        return new IntentBinding('checkout', 'intent-'.$ref, 'op-'.$ref, $route, 'acct', 'binding-v1', $key, 'sha256:'.str_repeat($digest, 64), $originalCharge);
    }

    private static function assertConflict(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected routing conflict');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::IdempotencyConflict, $exception->problem);
        }
    }
}
