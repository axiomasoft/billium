<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Packman\Attributes\Api;
use RuntimeException;

#[Api(since: '1.0')]
final readonly class ProcessBarrier
{
    public function __construct(private DurableJournal $journal) {}

    public function arrive(string $name): void
    {
        $this->journal->append(new JournalEvent('barrier', ['name' => $name], ProcessIdentity::current()));
    }

    /** @return list<int> */
    public function await(string $name, int $participants, int $timeoutMilliseconds = 5000): array
    {
        $deadline = hrtime(true) + $timeoutMilliseconds * 1000000;
        do {
            $pids = [];
            foreach ($this->journal->records() as $record) {
                if ($record instanceof JournalEvent && $record->kind === 'barrier' && ($record->data['name'] ?? null) === $name) {
                    $pids[] = $record->pid;
                }
            }
            $pids = array_values(array_unique($pids));
            if (count($pids) >= $participants) {
                return $pids;
            }
            usleep(1000);
        } while (hrtime(true) < $deadline);
        throw new RuntimeException('Barrier timed out: '.$name);
    }
}
