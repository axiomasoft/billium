<?php

declare(strict_types=1);

namespace Billium\Server\Data;

use Packman\Attributes\Api;

/** Host-resolved context. Capabilities are descriptive and confer no permission. */
#[Api(since: '1.0')]
final readonly class ExecutionContext
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<SourceAuthorityGrant>  $sourceAuthorities
     * @param  list<string>  $permissions  Core action grants issued by host policy.
     * @param  list<string>  $executionAuthorities  Authenticated execution profiles, independent of observation intake grants.
     */
    public function __construct(
        public string $installationRef,
        public string $environment,
        public string $sellerRef,
        public string $actorRef,
        public array $capabilities = [],
        public array $sourceAuthorities = [],
        public array $permissions = [],
        public ?string $intentOwnerNamespace = null,
        public array $executionAuthorities = [],
    ) {}

    public function sellerScope(): SellerScope
    {
        return new SellerScope($this->installationRef, $this->environment, $this->sellerRef);
    }
}
