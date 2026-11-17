<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * The authenticated caller of a root operation. Payload refs are selectors, never authority: the seller/environment of
 * every command must equal these values (INV-14).
 */
#[Api(since: '1.0')]
final readonly class Caller
{
    /**
     * @param  list<string>  $sourceAuthorities  authenticated fact sources this caller may speak for (D60)
     * @param  list<string>  $intentOwnerNamespaces  authenticated business intent owners this caller may act for
     */
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $installationRef,
        public string $actorRef,
        public array $sourceAuthorities = [],
        public array $intentOwnerNamespaces = [],
    ) {
        foreach (['sellerRef' => $sellerRef, 'environment' => $environment, 'installationRef' => $installationRef, 'actorRef' => $actorRef] as $name => $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new InvalidArgumentException("Caller {$name} must be a nonempty bounded string");
            }
        }
    }
}
