<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Inbox;

use Billium\Core\Contracts\TransactionContext;
use Billium\Platform\Contracts\BootstrapItemHandler;
use Billium\Platform\Contracts\CustodyHandler;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Data\Inbox\InboxRow;
use Billium\Platform\Tests\PlatformTestCase;
use Closure;
use stdClass;

/**
 * Handler double of the owning module: it writes one journal row per economic application inside the root Tx (so a
 * rollback removes it) and answers from a script keyed by external event id, applied otherwise.
 */
final class JournalHandler implements BootstrapItemHandler, CustodyHandler
{
    public int $calls = 0;

    /**
     * @param  array<string, HandlerOutcome>  $script  event id (or `operation#revision` of a snapshot item) => outcome
     * @param  Closure(TransactionContext, InboxRow, self): void|null  $inside
     */
    public function __construct(public array $script = [], private readonly int $sleepMs = 0, private readonly ?Closure $inside = null) {}

    public function handle(TransactionContext $context, InboxRow $row, stdClass $payload): HandlerOutcome
    {
        $this->calls++;
        if ($this->inside !== null) {
            ($this->inside)($context, $row, $this); // the owning module plans and locks its own rows here
        }
        $this->journal($context, 'live', $row->externalEventId, $row->sourceSequence);
        if ($this->sleepMs > 0) {
            PlatformTestCase::connection($context->connectionName())->select('SELECT pg_sleep(?)', [$this->sleepMs / 1000]);
        }

        return $this->script[$row->externalEventId] ?? HandlerOutcome::applied('receipt:'.$row->externalEventId);
    }

    public function apply(TransactionContext $context, BootstrapItem $item): HandlerOutcome
    {
        $this->calls++;
        $key = $item->operationRef.'#'.$item->revision;
        $this->journal($context, 'snapshot', $key, 0);

        return $this->script[$key] ?? HandlerOutcome::applied('receipt:'.$key);
    }

    private function journal(TransactionContext $context, string $kind, string $ref, int $sequence): void
    {
        PlatformTestCase::connection($context->connectionName())->insert(
            'INSERT INTO billium_test_journal (kind, ref, source_sequence) VALUES (?, ?, ?)',
            [$kind, $ref, $sequence],
        );
    }
}
