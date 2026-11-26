<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Independent business owner and fact source dimensions. In signed evidence a null dimension covers every value.
 * In an operation selection a null dimension means the operation uses no qualifier in that dimension.
 */
#[Api(since: '1.0')]
final readonly class FinancialScope
{
    public function __construct(
        public string $installationRef,
        public ?string $sellerRef,
        public ?string $environment,
        public ?string $intentOwnerNamespace,
        public ?string $sourceAuthority = null,
    ) {
        foreach (get_object_vars($this) as $claim) {
            if (is_string($claim) && trim($claim) === '') {
                throw new InvalidArgumentException('Authority claims must be nonempty');
            }
        }
    }

    public function permits(Caller $caller, ?string $namespace, ?string $sourceAuthority = null): bool
    {
        return $this->installationRef === $caller->installationRef
            && ($this->sellerRef === null || $this->sellerRef === $caller->sellerRef)
            && ($this->environment === null || $this->environment === $caller->environment)
            && ($this->intentOwnerNamespace === null || $this->intentOwnerNamespace === $namespace)
            && ($this->sourceAuthority === null || $this->sourceAuthority === $sourceAuthority);
    }
}
