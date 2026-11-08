<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Inbox;

use Billium\Platform\Data\Inbox\InboxRow;
use Billium\Platform\Internal\Installation\Rows;
use stdClass;

/** Mapping of billium_inbox_message records. */
final class InboxRecords
{
    public static function row(mixed $record): InboxRow
    {
        $partition = Rows::field($record, 'partition_id');
        $result = Rows::field($record, 'result_ref');

        return new InboxRow(
            Rows::int(Rows::field($record, 'id')), Rows::string(Rows::field($record, 'channel')), Rows::string(Rows::field($record, 'source_authority')),
            Rows::string(Rows::field($record, 'source_lineage')), Rows::string(Rows::field($record, 'seller_ref')), Rows::string(Rows::field($record, 'environment')),
            Rows::string(Rows::field($record, 'external_event_id')), Rows::string(Rows::field($record, 'content_digest')),
            $partition === null ? null : Rows::int($partition), Rows::string(Rows::field($record, 'partition_ref')),
            Rows::int(Rows::field($record, 'source_sequence')), Rows::string(Rows::field($record, 'operation_ref')),
            Rows::int(Rows::field($record, 'operation_revision')), Rows::string(Rows::field($record, 'status')),
            Rows::int(Rows::field($record, 'attempts')), $result === null ? null : Rows::string($result),
        );
    }

    /** The stored envelope with objects kept as objects. */
    public static function payload(mixed $record): stdClass
    {
        $payload = json_decode(Rows::string(Rows::field($record, 'payload')), false, 64, JSON_THROW_ON_ERROR);

        return $payload instanceof stdClass ? $payload : new stdClass;
    }
}
