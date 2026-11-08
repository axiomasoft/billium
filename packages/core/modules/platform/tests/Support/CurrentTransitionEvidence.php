<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Support;

use Billium\Core\Contracts\Modules\ModuleTransitionEvidence;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Modules\ModuleState;
use Billium\Core\Data\Modules\ModuleTransitionFacts;
use Billium\Platform\Tests\PlatformTestCase;

/** Owning-module double: all recorded events are outstanding until delivery exists; never echoes request facts. */
final class CurrentTransitionEvidence implements ModuleTransitionEvidence
{
    public int $pendingOperations = 0;

    public bool $available = true;

    /** @var array<string, int> */
    public array $pendingByModule = [];

    public function read(TransactionContext $context, ModuleState $current, string $evidenceRef): ?ModuleTransitionFacts
    {
        app(OperationRunner::class)->assertCurrent($context);
        if (! $this->available) {
            return null;
        }
        $events = PlatformTestCase::connection($context->connectionName())->table('billium_outbox_event')->count();

        return new ModuleTransitionFacts(pendingOperations: $this->pendingByModule[$current->moduleId] ?? $this->pendingOperations, outboxInboxGaps: $events);
    }
}
