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
    /** @var list<string> Dedicated authenticated action permissions, independently of economic fact sources. */
    public array $permissions;

    /**
     * @param  list<string>  $sourceAuthorities  authenticated fact sources this caller may speak for (D60)
     * @param  list<string>  $intentOwnerNamespaces  authenticated business intent owners this caller may act for
     * @param  array<mixed>  $permissions  host-authenticated action grants; never taken from command payloads
     */
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $installationRef,
        public string $actorRef,
        public array $sourceAuthorities = [],
        public array $intentOwnerNamespaces = [],
        array $permissions = [],
    ) {
        foreach (['sellerRef' => $sellerRef, 'environment' => $environment, 'installationRef' => $installationRef, 'actorRef' => $actorRef] as $name => $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new InvalidArgumentException("Caller {$name} must be a nonempty bounded string");
            }
        }
        if (! array_is_list($permissions) || count($permissions) > 256) {
            throw new InvalidArgumentException('Caller action permissions must be a bounded list');
        }
        $grants = [];
        foreach ($permissions as $permission) {
            if (! is_string($permission) || $permission === '' || strlen($permission) > 200 || str_contains($permission, "\0")) {
                throw new InvalidArgumentException('Caller action permission must be a nonempty bounded string');
            }
            $grants[] = $permission;
        }
        $this->permissions = array_values(array_unique($grants, SORT_STRING));
    }
}
