<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Authenticated statement response. routeDigest is sha256:<hex> of the JCS object whose schema is
 * billium.execution.inventory-route.v1. Its other keys are installationRef, sellerRef, environment, ownerNamespace,
 * sourceAuthority, providerId, merchantAccountRef, merchantRevisionRef, merchantRevisionDigest (the frozen row's
 * digest), credentialVersionRef (the credential actually used) and currency. All values are exact strings.
 */
#[Api(since: '1.0')]
final readonly class QualifiedInventoryPage
{
    /** @var list<ProviderEvidence|InventoryReportFact> */
    public array $items;

    /** @param array<mixed> $items */
    public function __construct(
        public string $routeDigest,
        public string $credentialVersionRef,
        public string $currency,
        public DateTimeImmutable $from,
        public DateTimeImmutable $through,
        public ?string $cursor,
        public ?string $nextCursor,
        public string $reportRef,
        public string $qualificationEvidenceRef,
        public string $sourceDigest,
        public ?DateTimeImmutable $completeThrough,
        array $items,
        public string $sourceAuthority,
        public string $ownerNamespace,
    ) {
        foreach ([$routeDigest, $credentialVersionRef, $sourceAuthority, $ownerNamespace, $reportRef, $qualificationEvidenceRef, $cursor, $nextCursor] as $selector) {
            if ($selector !== null && (trim($selector) === '' || strlen($selector) > 200)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid provider report identity');
            }
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/D', $routeDigest) !== 1 || preg_match('/^sha256:[0-9a-f]{64}$/D', $sourceDigest) !== 1 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || $from >= $through || ($nextCursor !== null && $completeThrough !== null) || ! array_is_list($items) || count($items) > 100) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid bounded report page');
        }
        foreach ($items as $item) {
            if (! $item instanceof ProviderEvidence && ! $item instanceof InventoryReportFact) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Unknown inventory line type');
            }
        }
        $this->items = $items;
    }
}
