<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Core\Contracts\TransactionContext;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\Connection;
use LogicException;

final class EventRows
{
    public static function scope(Connection $db, TransactionContext $context): FeedScope
    {
        $caller = $context->caller();
        $row = $db->selectOne('SELECT incarnation FROM public.billium_installation_state WHERE installation_ref = ?', [$caller->installationRef]);

        return new FeedScope($caller->installationRef, $caller->sellerRef, $caller->environment, (string) Rows::int(Rows::field($row, 'incarnation')));
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
            'occurredAt' => new \DateTimeImmutable(Rows::string(Rows::field($row, 'occurred_at')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'digest' => Rows::string(Rows::field($row, 'digest')), 'payload' => json_decode(Rows::string(Rows::field($row, 'payload')), false, 512, JSON_THROW_ON_ERROR)];
        if ($body['schema'] !== 'billium.event.v1') {
            $body['eventContractRevision'] = Rows::string(Rows::field($row, 'contract_revision'));
        }

        return $body;
    }
}
