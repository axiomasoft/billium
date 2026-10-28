<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Console;

use Billium\Platform\Internal\Outbox\OutboxPruner;
use Illuminate\Console\Command;
use LogicException;

final class PruneCommand extends Command
{
    protected $signature = 'billium:outbox:prune';

    protected $description = 'Prune a proven delivered prefix under retention and bootstrap pins (D52/D60)';

    public function handle(OutboxPruner $pruner): int
    {
        try {
            $this->line('pruned='.$pruner->prune());

            return self::SUCCESS;
        } catch (LogicException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
