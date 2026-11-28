<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Feed;

use InvalidArgumentException;
use Packman\Attributes\Api;

/** Decimal strings preserve xid8 and bigint exactly in PHP/Dart/wire clients. */
#[Api(since: '1.0')]
final readonly class FeedPosition
{
    public function __construct(public string $txId = '0', public string $id = '0')
    {
        foreach ([$txId, $id] as $number) {
            if (preg_match('/^(0|[1-9][0-9]*)$/D', $number) !== 1) {
                throw new InvalidArgumentException('Invalid D52 position');
            }
        }
    }

    /** @return array{txId:string,id:string} */
    public function toArray(): array
    {
        return ['txId' => $this->txId, 'id' => $this->id];
    }
}
