<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Platform\Contracts\Outbox\OutboxDeliveryQueries;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Outbox\OutboxDeliveryStatus;
use Billium\Platform\Internal\Installation\Rows;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseOutboxDeliveryQueries implements OutboxDeliveryQueries
{
    private const string SELECT = <<<'SQL'
        SELECT e.event_id, e.type, e.aggregate_ref, e.aggregate_revision, d.id, d.destination_ref,
               d.status, d.available_at, d.claim_until, d.attempts, d.consecutive_failures,
               d.last_error_class, d.park_reason, d.delivered_at
          FROM public.billium_outbox_delivery d JOIN public.billium_outbox_event e USING (event_id)
        SQL;

    private const string SCOPE = 'e.seller_ref = ? AND e.environment = ? AND e.source_scope::jsonb = ?::jsonb';

    public function __construct(
        private DatabaseManager $databases,
        private BilliumOptions $options,
        private CallerContext $callers,
    ) {}

    public function due(FeedScope $scope, string $destinationRef, int $limit = 100): array
    {
        self::validate($destinationRef, $limit);
        $select = self::SELECT;
        $selection = self::SCOPE;

        return $this->read($scope, static function (Connection $db, array $bindings) use ($select, $selection, $destinationRef, $limit): array {
            // Each branch has its own clock column and partial index. Limit after authorizing every candidate.
            $rows = $db->select(<<<SQL
                SELECT * FROM (
                    ({$select} WHERE {$selection} AND d.destination_ref = ?
                        AND d.status = 'pending' AND d.available_at <= statement_timestamp()
                        ORDER BY d.available_at,d.id LIMIT ?)
                    UNION ALL
                    ({$select} WHERE {$selection} AND d.destination_ref = ?
                        AND d.status = 'in_flight' AND d.claim_until <= statement_timestamp()
                        ORDER BY d.claim_until,d.id LIMIT ?)
                ) due_delivery
                ORDER BY coalesce(claim_until,available_at),id LIMIT ?
                SQL, [...$bindings, $destinationRef, $limit, ...$bindings, $destinationRef, $limit, $limit]);

            return array_values(array_map(self::status(...), $rows));
        });
    }

    public function byEvent(FeedScope $scope, string $eventId, int $limit = 100): array
    {
        self::validate($eventId, $limit);
        $select = self::SELECT;
        $selection = self::SCOPE;

        return $this->read($scope, static fn (Connection $db, array $bindings): array => array_values(array_map(self::status(...), $db->select(
            "{$select} WHERE {$selection} AND e.event_id = ? ORDER BY d.destination_ref LIMIT ?", [...$bindings, $eventId, $limit],
        ))));
    }

    /**
     * @param  Closure(Connection, array{string,string,string}): list<OutboxDeliveryStatus>  $read
     * @return list<OutboxDeliveryStatus>
     */
    private function read(FeedScope $scope, Closure $read): array
    {
        $caller = $this->callers->current();
        if ($caller->installationRef !== $scope->installationRef || $caller->sellerRef !== $scope->sellerRef || $caller->environment !== $scope->environment
            || $scope->channel !== 'events' || $scope->sourceAuthority !== 'billium') {
            throw new ProblemException(ProblemCode::AuthDenied, 'Delivery feed scope is not authenticated');
        }
        $db = $this->databases->connection($this->options->connection);
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new LogicException('Delivery queries require an independent read-only root');
        }

        return $db->transaction(static function () use ($db, $scope, $read): array {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $row = $db->selectOne('SELECT incarnation FROM public.billium_installation_state WHERE installation_ref = ?', [$scope->installationRef]);
            if ($row === null || (string) Rows::int(Rows::field($row, 'incarnation')) !== $scope->lineage) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Delivery feed lineage differs from current source');
            }

            return $read($db, [$scope->sellerRef, $scope->environment, $scope->key()]);
        });
    }

    private static function validate(string $reference, int $limit): void
    {
        if ($reference === '' || strlen($reference) > 200 || str_contains($reference, "\0") || $limit < 1 || $limit > 100) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid delivery query selector');
        }
    }

    private static function status(mixed $row): OutboxDeliveryStatus
    {
        $text = static fn (string $field): string => Rows::string(Rows::field($row, $field));
        $integer = static fn (string $field): int => Rows::int(Rows::field($row, $field));
        $claimUntil = Rows::nullableString(Rows::field($row, 'claim_until'));
        $deliveredAt = Rows::nullableString(Rows::field($row, 'delivered_at'));

        return new OutboxDeliveryStatus($text('event_id'), $text('type'), $text('aggregate_ref'), $integer('aggregate_revision'),
            $text('destination_ref'), $text('status'), new DateTimeImmutable($text('available_at')), $claimUntil === null ? null : new DateTimeImmutable($claimUntil),
            $integer('attempts'), $integer('consecutive_failures'), Rows::nullableString(Rows::field($row, 'last_error_class')),
            Rows::nullableString(Rows::field($row, 'park_reason')), $deliveredAt === null ? null : new DateTimeImmutable($deliveredAt));
    }
}
