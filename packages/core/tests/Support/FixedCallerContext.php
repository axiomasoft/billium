<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Data\Caller;

/** Test double of the host-authenticated caller; tests switch the caller to prove scope checks. */
final class FixedCallerContext implements CallerContext
{
    public function __construct(private Caller $caller) {}

    public static function default(string $sellerRef = 'seller-a', string $environment = 'test', string $installationRef = 'inst-test'): self
    {
        return new self(new Caller($sellerRef, $environment, $installationRef, 'actor-1'));
    }

    public function current(): Caller
    {
        return $this->caller;
    }

    public function set(Caller $caller): void
    {
        $this->caller = $caller;
    }

    public function as(string $sellerRef, ?string $environment = null): void
    {
        $this->caller = new Caller($sellerRef, $environment ?? $this->caller->environment, $this->caller->installationRef, $this->caller->actorRef, $this->caller->sourceAuthorities);
    }
}
