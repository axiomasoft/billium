<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Console;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final class RelayCommand extends Command
{
    protected $signature = 'billium:outbox:relay {--limit=} {--commands : Relay the separate internal command lane}';

    protected $description = 'Claim and relay due outbox deliveries';

    public function handle(BilliumOptions $options): int
    {
        $limit = $this->option('limit') ?? $options->outbox->relayBatch;
        if ((! is_int($limit) && ! ctype_digit($limit)) || (int) $limit < 1 || (int) $limit > 1000) {
            $this->error('limit must be between 1 and 1000');

            return self::FAILURE;
        }
        try {
            $relay = $this->option('commands') === true ? app(CommandOutboxRelay::class) : app(OutboxRelay::class);
            $report = $relay->relay((int) $limit);
            $this->line("claimed={$report->claimed} delivered={$report->delivered} failed={$report->failed} stale={$report->stale}");

            return self::SUCCESS;
        } catch (Throwable $error) {
            // The console shows only the class; the message may carry credentials or payloads, so it goes to the host log handler.
            report($error);
            $this->error('Relay unavailable ('.$error::class.'); verify host publisher and outbox configuration');

            return self::FAILURE;
        }
    }
}
