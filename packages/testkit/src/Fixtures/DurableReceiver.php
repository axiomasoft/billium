<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Packman\Attributes\Api;
use RuntimeException;

#[Api(since: '1.0')]
final readonly class DurableReceiver
{
    public function __construct(public DurableJournal $journal) {}

    public function deliver(ReceiverReceipt $receipt): ReceiverReceipt
    {
        $this->journal->append(new JournalEvent('receiver.call', ['event' => $receipt->eventRef], ProcessIdentity::current()));

        return $this->journal->transact(static function (array $records) use ($receipt): array {
            foreach ($records as $old) {
                if (! $old instanceof ReceiverReceipt) {
                    continue;
                }
                if ($old->eventRef === $receipt->eventRef || ($old->businessRef === $receipt->businessRef && $old->revision === $receipt->revision)) {
                    if ($old->digest !== $receipt->digest || $old->captured !== $receipt->captured || $old->refunded !== $receipt->refunded || $old->businessRef !== $receipt->businessRef || $old->revision !== $receipt->revision) {
                        throw new RuntimeException('Receiver immutable revision conflict');
                    }

                    return ['value' => $old, 'append' => []];
                }
            }
            if ($receipt->revision < 1 || $receipt->captured < 0 || $receipt->refunded < 0 || $receipt->refunded > $receipt->captured) {
                throw new RuntimeException('Invalid receiver totals');
            }

            return ['value' => $receipt, 'append' => [$receipt]];
        });
    }
}
