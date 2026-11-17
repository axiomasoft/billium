<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Console;

use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Throwable;

/** Drain worker of the observation path: one economic event per root Tx (inbox-feed), for the host's current caller. */
#[Internal]
final class DrainParkedCommand extends Command
{
    protected $signature = 'billium:ledger:drain-parked {--limit=100 : Parked observations to retry} {--partition= : Also drain this partition id}';

    protected $description = 'Retry dependency-parked payment observations and drain partitions one sequence per root (D60)';

    public function handle(ParkedDrainer $drainer): int
    {
        $limit = $this->option('limit');
        $partition = $this->option('partition');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000
            || ($partition !== null && ! ctype_digit($partition))) {
            $this->error('limit must be between 1 and 1000 and partition a positive id');

            return self::FAILURE;
        }
        try {
            $drained = $partition === null ? [] : $drainer->drainPartition((int) $partition);
            $statuses = $drainer->retryParked((int) $limit);
            $counts = array_count_values($statuses);
            ksort($counts);
            $this->line('drained='.count($drained).' retried='.count($statuses).' '.http_build_query($counts, '', ' '));

            return self::SUCCESS;
        } catch (Throwable $error) {
            // Messages may carry payload fragments; the console shows only the class, the host log handler gets the rest.
            report($error);
            $this->error('Drain unavailable ('.$error::class.')');

            return self::FAILURE;
        }
    }
}
