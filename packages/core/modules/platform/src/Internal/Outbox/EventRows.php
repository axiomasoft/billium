<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Internal\Installation\Rows;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use JsonException;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final class EventRows
{
    public static function scope(Connection $db, TransactionContext $context): FeedScope
    {
        $caller = $context->caller();
        $row = $db->selectOne('SELECT incarnation FROM public.billium_installation_state WHERE installation_ref = ?', [$caller->installationRef]);

        return new FeedScope($caller->installationRef, $caller->sellerRef, $caller->environment, (string) Rows::int(Rows::field($row, 'incarnation')));
    }

    /**
     * Source scopes are compared by value, as SQL does with::jsonb; the text form is not guaranteed to be canonical.
     */
    public static function sameScope(mixed $stored, FeedScope $scope): bool
    {
        if (! is_string($stored)) {
            return false;
        }
        try {
            $codec = new Codec;

            return $codec->canonicalize(json_decode($stored, false, 512, JSON_THROW_ON_ERROR)) === $scope->key();
        } catch (JsonException) {
            return false;
        }
    }

    /** @param list<string> $destinations */
    public static function deliveries(Connection $db, string $table, string $id, array $destinations): void
    {
        if (! in_array($table, ['billium_outbox_delivery', 'billium_outbox_command_delivery'], true)) {
            throw new LogicException('Unknown delivery lane');
        }
        foreach (array_unique($destinations) as $destination) {
            if ($destination === '' || strlen($destination) > 200) {
                throw new LogicException('Destination is a nonempty bounded reference');
            }
            $db->insert("INSERT INTO public.{$table}(event_id,destination_ref) VALUES (?,?)", [$id, $destination]);
        }
    }

    /** @return array<string,mixed> */
    public static function envelope(mixed $row): array
    {
        $body = ['schema' => Rows::string(Rows::field($row, 'schema_version')), 'eventId' => Rows::string(Rows::field($row, 'event_id')),
            'eventType' => Rows::string(Rows::field($row, 'type')), 'sellerRef' => Rows::string(Rows::field($row, 'seller_ref')),
            'aggregateRef' => Rows::string(Rows::field($row, 'aggregate_ref')), 'revision' => Rows::int(Rows::field($row, 'aggregate_revision')),
            'occurredAt' => new DateTimeImmutable(Rows::string(Rows::field($row, 'occurred_at')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'digest' => Rows::string(Rows::field($row, 'digest')), 'payload' => json_decode(Rows::string(Rows::field($row, 'payload')), false, 512, JSON_THROW_ON_ERROR)];
        if ($body['schema'] !== 'billium.event.v1') {
            $body['eventContractRevision'] = Rows::string(Rows::field($row, 'contract_revision'));
        }

        if ($body['schema'] === 'billium.event.v4') {
            $scope = json_decode(Rows::string(Rows::field($row, 'source_scope')), false, 512, JSON_THROW_ON_ERROR);
            if (! $scope instanceof stdClass || ! is_string($scope->installationRef ?? null) || $scope->installationRef === ''
                || ! is_string($scope->environment ?? null) || $scope->environment === ''
                || $scope->environment !== Rows::string(Rows::field($row, 'environment')) || ($scope->sellerRef ?? null) !== $body['sellerRef']) {
                throw new LogicException('Public event scope differs from its authenticated source');
            }
            $body['installationRef'] = $scope->installationRef;
            $body['environment'] = $scope->environment;
        }

        return $body;
    }
}
