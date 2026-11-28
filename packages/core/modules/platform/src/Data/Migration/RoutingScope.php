<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Billium\Contracts\Contracts\Codec;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** The consumer's origin scope stays fixed across changes of destination executor or installation. */
#[Api(since: '1.0')]
final readonly class RoutingScope
{
    public function __construct(
        public string $installationRef,
        public string $environment,
        public string $sellerRef,
        public string $intentOwnerNamespace,
    ) {
        foreach ([$installationRef, $environment, $sellerRef, $intentOwnerNamespace] as $value) {
            if (trim($value) === '' || strlen($value) > 200) {
                throw new InvalidArgumentException('Incomplete intent routing scope');
            }
        }
    }

    public function key(): string
    {
        return (new Codec)->digest(get_object_vars($this));
    }
}
