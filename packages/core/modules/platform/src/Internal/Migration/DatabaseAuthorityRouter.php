<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Migration;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Contracts\Migration\AuthorityRouter;
use Billium\Platform\Data\Migration\ExecutionRoute;
use Billium\Platform\Data\Migration\IntentBinding;
use Billium\Platform\Data\Migration\RoutingScope;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/** An independent host routing journal. All registry inserts and barriers serialize on the scope row. */
#[Internal]
final readonly class DatabaseAuthorityRouter implements AuthorityRouter
{
    public function __construct(private Connection $db) {}

    /**
     * Privileged initialization with admissions stopped and complete existing bindings, including tombstones.
     * The inventory must list original charges before their refunds.
     *
     * @param  list<IntentBinding>  $existing
     */
    public function initialize(RoutingScope $scope, ExecutionRoute $route, array $existing): void
    {
        $this->outsideTransaction();
        $this->db->transaction(function () use ($scope, $route, $existing): void {
            $this->routes($scope)->insert([
                'scope_key' => $scope->key(), 'executor_ref' => $route->executorRef, 'routing_epoch' => $route->routingEpoch,
            ]);
            foreach ($existing as $binding) {
                if ($binding->route->routingEpoch > $route->routingEpoch) {
                    self::conflict('An existing binding is beyond the initial barrier');
                }
                $this->store($scope, $binding, null);
            }
        });
    }

    /** Privileged compare-and-set. Release, install and default route changes have no effect here. */
    public function advance(RoutingScope $scope, ExecutionRoute $expected, ExecutionRoute $next): void
    {
        $this->outsideTransaction();
        if ($next->routingEpoch !== $expected->routingEpoch + 1) {
            self::conflict('A routing barrier requires the next epoch');
        }
        $this->db->transaction(function () use ($scope, $expected, $next): void {
            $row = $this->lockRoute($scope);
            $current = $this->route($row);
            if ($current->matches($next) && $row?->previous_executor_ref === $expected->executorRef) {
                return;
            }
            if (! $current->matches($expected)) {
                self::conflict('Stale routing barrier');
            }
            $this->routes($scope)->update([
                'previous_executor_ref' => $expected->executorRef, 'executor_ref' => $next->executorRef, 'routing_epoch' => $next->routingEpoch,
            ]);
        });
    }

    public function current(RoutingScope $scope): ExecutionRoute
    {
        return $this->route($this->routes($scope)->first());
    }

    public function lookup(RoutingScope $scope, string $operationRef): ?IntentBinding
    {
        $row = $this->intents($scope)->where('operation_ref', $operationRef)->first();

        return $row === null ? null : $this->binding($row);
    }

    public function reserve(RoutingScope $scope, IntentBinding $intent): IntentBinding
    {
        $this->outsideTransaction();

        return $this->db->transaction(function () use ($scope, $intent): IntentBinding {
            $current = $this->route($this->lockRoute($scope));

            return $this->store($scope, $intent, $current);
        });
    }

    private function lockRoute(RoutingScope $scope): ?stdClass
    {
        $row = $this->db->selectOne(
            'UPDATE public.billium_authority_route SET routing_epoch = routing_epoch WHERE scope_key = ? RETURNING *',
            [$scope->key()],
            false,
        );

        return $row instanceof stdClass ? $row : null;
    }

    private function store(RoutingScope $scope, IntentBinding $intent, ?ExecutionRoute $current): IntentBinding
    {
        $kind = $intent->originalChargeRef === null ? 'charge' : 'refund';
        $intentRow = $this->intents($scope)->where('source_context', $intent->sourceContext)
            ->where('business_intent_ref', $intent->businessIntentRef)->where('kind', $kind)->first();
        if ($intentRow !== null) {
            $stored = $this->binding($intentRow);
            if (! $stored->matches($intent)) {
                self::conflict('Business intent already has an immutable binding');
            }

            return $stored;
        }

        if ($intent->originalChargeRef === null) {
            if ($current !== null && ! $intent->route->matches($current)) {
                self::conflict('New business intent requires the current route');
            }
        } else {
            $charge = $this->lookup($scope, $intent->originalChargeRef);
            if ($charge === null || $charge->originalChargeRef !== null || ! $charge->route->matches($intent->route)
                || $charge->merchantAccountRef !== $intent->merchantAccountRef || $charge->bindingRevision !== $intent->bindingRevision) {
                self::conflict('Refund requires the original charge binding');
            }
        }
        if ($this->intents($scope)->where('operation_ref', $intent->operationRef)->exists()
            || $this->intents($scope)->where('command_key', $intent->commandKey)->exists()) {
            self::conflict('Operation or command key already belongs to another intent');
        }
        $this->intents($scope)->insert([
            'scope_key' => $scope->key(), 'kind' => $kind, 'source_context' => $intent->sourceContext,
            'business_intent_ref' => $intent->businessIntentRef, 'operation_ref' => $intent->operationRef,
            'executor_ref' => $intent->route->executorRef, 'routing_epoch' => $intent->route->routingEpoch,
            'merchant_account_ref' => $intent->merchantAccountRef, 'binding_revision' => $intent->bindingRevision,
            'command_key' => $intent->commandKey, 'payload_digest' => $intent->payloadDigest,
            'original_charge_ref' => $intent->originalChargeRef,
        ]);

        return $intent;
    }

    private function routes(RoutingScope $scope): Builder
    {
        return $this->db->table('public.billium_authority_route')->useWritePdo()->where('scope_key', $scope->key());
    }

    private function intents(RoutingScope $scope): Builder
    {
        return $this->db->table('public.billium_intent_binding')->useWritePdo()->where('scope_key', $scope->key());
    }

    private function route(?stdClass $row): ExecutionRoute
    {
        if ($row === null) {
            self::conflict('Routing scope has no established barrier');
        }
        if (! is_string($row->executor_ref) || ! is_int($row->routing_epoch)) {
            throw new LogicException('Invalid routing journal row');
        }

        return new ExecutionRoute($row->executor_ref, $row->routing_epoch);
    }

    private function binding(stdClass $row): IntentBinding
    {
        if (! is_string($row->source_context) || ! is_string($row->business_intent_ref) || ! is_string($row->operation_ref)
            || ! is_string($row->merchant_account_ref) || ! is_string($row->binding_revision) || ! is_string($row->command_key)
            || ! is_string($row->payload_digest) || ($row->original_charge_ref !== null && ! is_string($row->original_charge_ref))) {
            throw new LogicException('Invalid binding journal row');
        }

        return new IntentBinding(
            $row->source_context, $row->business_intent_ref, $row->operation_ref, $this->route($row),
            $row->merchant_account_ref, $row->binding_revision, $row->command_key, $row->payload_digest, $row->original_charge_ref,
        );
    }

    private function outsideTransaction(): void
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new LogicException('Routing must commit before a caller can dispatch');
        }
    }

    private static function conflict(string $reason): never
    {
        throw new ProblemException(ProblemCode::IdempotencyConflict, $reason);
    }
}
