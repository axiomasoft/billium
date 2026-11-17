<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/** D45/D60: consumers receive scoped metadata; table access stays with platform on the supplied snapshot connection. */
#[Internal]
final readonly class DatabaseFeedRecoveryQueries implements FeedRecoveryQueries
{
    public function __construct(private DatabaseManager $databases, private CallerContext $callers) {}

    public function state(string $connectionName, string $channel, ?string $sourceAuthority = null, ?string $partitionRef = null): array
    {
        $caller = $this->callers->current();
        $db = $this->databases->connection($connectionName);
        if ($db->transactionLevel() !== 1 || ! $db->getPdo()->inTransaction()) {
            throw new LogicException('Feed recovery reads require a REPEATABLE READ READ ONLY root transaction');
        }
        $settings = $db->selectOne("SELECT current_setting('transaction_isolation') AS isolation,current_setting('transaction_read_only') AS read_only");
        if (Rows::field($settings, 'isolation') !== 'repeatable read' || Rows::field($settings, 'read_only') !== 'on') {
            throw new LogicException('Feed recovery reads require a REPEATABLE READ READ ONLY root transaction');
        }
        $recovering = false;
        $bootstrap = 'not_started';
        $snapshots = [];
        foreach (['billium_feed_partition', 'billium_feed_bootstrap_session'] as $table) {
            $query = $db->table($table)->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)->where('channel', $channel);
            if ($sourceAuthority !== null) {
                $query->where('source_authority', $sourceAuthority);
            }
            if ($partitionRef !== null) {
                $query->where('partition_ref', $partitionRef);
            }
            foreach ($query->orderBy('id')->get() as $row) {
                $payload = [];
                foreach (get_object_vars($row) as $key => $value) {
                    $payload[(string) $key] = $value;
                }
                $snapshots[] = ['identity' => $table.':'.Rows::int($row->id), 'revision' => 1,
                    'digest' => (new Codec)->digest($payload), 'payload' => $payload];
                if ($table === 'billium_feed_partition') {
                    $recovering = $recovering || $row->state === 'recovery_required';
                } else {
                    $bootstrap = Rows::string($row->status);
                }
            }
        }

        return ['recoveryRequired' => $recovering || $bootstrap !== 'not_started' && $bootstrap !== 'adopted',
            'bootstrapStatus' => $bootstrap, 'snapshots' => $snapshots];
    }
}
