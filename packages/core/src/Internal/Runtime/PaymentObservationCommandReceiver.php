<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runtime;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\InternalCommandReceiver;
use Billium\Core\Contracts\Runtime\ScopedCallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FactualCommandBinding;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\SourceAuthorityGrant;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Contracts\QualifiedObservationApplier;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/** Separate receiving root: transport ACK follows durable ledger custody, never implies projection applied. */
#[Internal]
final readonly class PaymentObservationCommandReceiver implements InternalCommandReceiver
{
    public const string DESTINATION = 'ledger.inbox.payment_observation';

    private const string PAYLOAD_SCHEMA_DIGEST = 'sha256:da5997970adafb92e302896000dedefecf2a6413c5dfb96694a64cee14fae9fd';

    public function __construct(
        private Codec $codec,
        private IncomingCommandAuthenticator $authenticator,
        private ScopedCallerContext $callers,
        private ObservationApplier $ledger,
        private DatabaseManager $databases,
        private ModuleRegistry $modules,
        private ?TransportObservationContext $origins = null,
    ) {}

    public function receive(string $destinationRef, array $message): void
    {
        foreach ($this->databases->getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                throw new LogicException('Observation receiving and authentication require transaction level zero');
            }
        }
        if ($destinationRef !== self::DESTINATION || ($message['schema'] ?? null) !== 'billium.command.v1'
            || ($message['commandType'] ?? null) !== PaymentObservation::SCHEMA) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Unrecognized factual command route');
        }
        foreach (['commandRef', 'businessRef', 'payloadDigest'] as $field) {
            if (! is_string($message[$field] ?? null) || $message[$field] === '' || strlen($message[$field]) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Missing bounded command identity');
            }
        }
        $owner = $this->modules->descriptor('execution');
        if ($owner === null) {
            throw new ProblemException(ProblemCode::SchemaUnsupported, 'Execution factual owner is not declared');
        }
        $declared = false;
        foreach ($owner->internalCommands as $declaration) {
            if ($declaration->commandSchema === 'billium.command.v1' && $declaration->commandType === PaymentObservation::SCHEMA
                && $declaration->payloadSchemaDigest === self::PAYLOAD_SCHEMA_DIGEST) {
                $declared = true;
            }
        }
        if (! $declared) {
            throw new ProblemException(ProblemCode::SchemaUnsupported, 'Execution factual contract is not declared');
        }
        $payload = $this->codec->canonicalize($message['payload'] ?? null);
        if (strlen($payload) > 65536 || ! hash_equals('sha256:'.hash('sha256', $payload), $message['payloadDigest'])) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Factual command payload digest or size differs');
        }
        $observation = $this->codec->decode($payload);
        if (! $observation instanceof PaymentObservation) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Expected payment observation');
        }
        $consume = function (SourceAuthorityGrant $grant, Caller $caller) use ($destinationRef, $message, $observation, $payload): void {
            if ($grant->expiresAt <= new DateTimeImmutable || $grant->audience !== self::DESTINATION
                || $grant->installationRef !== $observation->installationRef || $grant->sellerRef !== $observation->sellerRef
                || $grant->environment !== $observation->environment || $grant->sourceAuthority !== $observation->sourceAuthority
                || $grant->sourceLineage !== $observation->sourceLineage
                || $message['businessRef'] !== ($observation->originalPaymentRef ?? $observation->operationRef)) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Observation does not match authenticated source scope');
            }
            if ($grant->intentOwnerNamespace !== null) {
                $scope = new FinancialScope($grant->installationRef, $grant->sellerRef, $grant->environment, $grant->intentOwnerNamespace, $grant->sourceAuthority);
                if ($this->origins === null || ! $this->ledger instanceof QualifiedObservationApplier || $grant->commandBindingDigest !== FactualCommandBinding::digest($destinationRef, $message, $scope)) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'Qualified observation binding or receiver unavailable');
                }

                $this->callers->run($caller, fn (): AllocationReceipt => $this->ledger->applyQualified($payload, $scope, $grant->proofDigest));

                return;
            }

            // Propagate parked/recovery/conflict after custody: retrying the same command is safe; no invented ACK.
            $this->callers->run($caller, fn (): AllocationReceipt => $this->ledger->apply($this->codec->encode($observation)));
        };
        if ($this->origins !== null) {
            $this->origins->run($destinationRef, $message, $consume);

            return;
        }
        $grant = $this->authenticator->authenticate($destinationRef, $message);
        $consume($grant, new Caller($grant->sellerRef, $grant->environment, $grant->installationRef, $grant->issuerRef, [$grant->sourceAuthority]));
    }
}
