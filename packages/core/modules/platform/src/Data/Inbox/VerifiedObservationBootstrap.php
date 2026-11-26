<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Inbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\FinancialScope;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class VerifiedObservationBootstrap
{
    public const string AUDIENCE = 'ledger.inbox.payment_observation.bootstrap';

    public function __construct(
        public FinancialScope $scope,
        public string $rootOperationRef,
        public string $providerAccountRef,
        public string $issuerRef,
        public string $audience,
        public DateTimeImmutable $expiresAt,
        public string $manifestBindingDigest,
        public string $proofDigest,
    ) {}

    public static function digest(BootstrapPartition $snapshot, FinancialScope $scope, string $rootOperationRef, string $providerAccountRef): string
    {
        return (new Codec)->digest(['schema' => 'billium.qualified-bootstrap-binding.v1', 'audience' => self::AUDIENCE,
            'scope' => get_object_vars($scope), 'rootOperationRef' => $rootOperationRef, 'providerAccountRef' => $providerAccountRef,
            'namespace' => $snapshot->namespace(), 'bootstrapRef' => $snapshot->bootstrapRef, 'cursor' => (string) $snapshot->cursorSequence,
            'count' => (string) $snapshot->expectedItemCount, 'snapshotDigest' => $snapshot->snapshotDigest, 'lease' => $snapshot->sourceRetentionLeaseRef]);
    }

    public function assertMatches(BootstrapPartition $snapshot): void
    {
        if ($this->scope->intentOwnerNamespace === null || $snapshot->sourceAuthority !== $this->scope->sourceAuthority
            || $snapshot->sellerRef !== $this->scope->sellerRef || $snapshot->environment !== $this->scope->environment
            || $snapshot->channel !== 'payment.observation' || $this->issuerRef === '' || $this->rootOperationRef === '' || $this->providerAccountRef === ''
            || $this->expiresAt <= new DateTimeImmutable || $this->audience !== self::AUDIENCE || preg_match('/^sha256:[a-f0-9]{64}$/D', $this->proofDigest) !== 1
            || $this->manifestBindingDigest !== self::digest($snapshot, $this->scope, $this->rootOperationRef, $this->providerAccountRef)
            || ! $snapshot->verifies()) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Bootstrap differs from authenticated original manifest');
        }
        foreach ($snapshot->items as $item) {
            if (($item->payload->installationRef ?? null) !== $this->scope->installationRef || ($item->payload->providerAccountRef ?? null) !== $this->providerAccountRef
                || ($item->payload->originalPaymentRef ?? $item->operationRef) !== $this->rootOperationRef) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Bootstrap item differs from the authenticated root or account');
            }
        }
    }
}
