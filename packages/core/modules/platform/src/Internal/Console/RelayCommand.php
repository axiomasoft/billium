<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Console;

use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Illuminate\Console\Command;
use Throwable;

final class RelayCommand extends Command
{
    protected $signature = 'billium:outbox:relay {--limit=} {--commands : Relay the separate internal command lane}';

    protected $description = 'Claim and relay due outbox deliveries (D60/D63)';

    public function handle(): int
    {
        $limit = $this->option('limit') ?? config('billium.outbox.relay_batch', 100);
        if (! is_scalar($limit) || ! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 1000) {
            $this->error('limit must be between 1 and 1000');

            return self::FAILURE;
        }
        try {
            $relay = $this->option('commands') ? app(CommandOutboxRelay::class) : app(OutboxRelay::class);
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
