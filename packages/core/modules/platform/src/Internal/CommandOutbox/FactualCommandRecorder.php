<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\CommandOutbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Modules\InternalCommandDeclaration;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Platform\Contracts\Outbox\FactualCommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedFactualCommand;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Billium\Platform\Data\Installation\FinancialSessionView;
use Billium\Platform\Internal\Installation\Sessions;
use Billium\Platform\Internal\Outbox\EventRows;
use Closure;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class FactualCommandRecorder implements FactualCommandOutbox
{
    /** The only module whose descriptor may declare and record payment observation facts. */
    private const string OWNER_MODULE_ID = 'execution';

    private const string SCHEMA_REF = 'spec/revisions/2026-10/schema/payment/observation.v1.json';

    private const string SCHEMA_DIGEST = 'sha256:da5997970adafb92e302896000dedefecf2a6413c5dfb96694a64cee14fae9fd';

    /** @param Closure(): OperationRunner $runner */
    public function __construct(private DatabaseManager $db, private ModuleRegistry $modules, private Closure $runner) {}

    public function record(TransactionContext $context, PreparedFactualCommand $command): int
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        ($this->runner)()->assertCurrent($context, self::OWNER_MODULE_ID);
        if (! in_array($context->spec()->mode, [FinancialMode::Active, FinancialMode::Recovery], true)
            || $context->spec()->operationClass !== ModuleOperationClass::ObservedFactRecording) {
            throw new LogicException('Factual command recording requires an admitted observed-fact root');
        }
        $epoch = $context->sessionEpoch();
        $selected = $context->spec()->financialScope;
        $caller = $context->caller();
        if ($selected === null || $selected->installationRef !== $caller->installationRef
            || $selected->sellerRef !== $caller->sellerRef || $selected->environment !== $caller->environment
            || $selected->intentOwnerNamespace === null || ! in_array($selected->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)
            || ! in_array($selected->sourceAuthority, ['remote-billium', 'standalone-billium'], true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Factual command requires an exact admitted execution namespace and source');
        }
        $descriptor = $this->modules->descriptor(self::OWNER_MODULE_ID);
        if ($descriptor === null || ! array_any($descriptor->internalCommands, static fn (InternalCommandDeclaration $declaration): bool => $declaration->commandSchema === PreparedFactualCommand::COMMAND_SCHEMA
            && $declaration->commandType === PreparedFactualCommand::COMMAND_TYPE
            && $declaration->payloadSchemaRef === self::SCHEMA_REF
            && $declaration->payloadSchemaDigest === self::SCHEMA_DIGEST)) {
            throw new LogicException('Exact factual command contract is not declared by execution');
        }
        // Decode the current raw body again: generated envelopes contain mutable stdClass input.
        $codec = new Codec;
        $payload = $codec->encode($command->observation);
        if (strlen($payload) > 65536) {
            throw new LogicException('Command payload exceeds bound');
        }
        $observation = $codec->decode($payload);
        if (! $observation instanceof PaymentObservation) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Factual command body must be a payment observation');
        }
        $session = Sessions::current($this->db->connection($context->connectionName()), $caller->installationRef);
        if ($observation->installationRef !== $caller->installationRef || $observation->sellerRef !== $caller->sellerRef
            || $observation->environment !== $caller->environment
            || $observation->sourceAuthority !== $selected->sourceAuthority
            || ! in_array($observation->sourceAuthority, $caller->sourceAuthorities, true)
            || $session === null || $session->epoch !== $epoch || self::sourceLineage($session) !== $observation->sourceLineage
            || $observation->partitionRef !== self::partitionRef($observation, $command->businessRef)
            || ($observation->originalPaymentRef ?? $observation->operationRef) !== $command->businessRef) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Factual command differs from admitted source, lineage or root');
        }
        $body = [];
        foreach (get_object_vars($observation->raw) as $name => $value) {
            if (! is_string($name)) {
                throw new LogicException('Observation property names must be strings');
            }
            $body[$name] = $value;
        }

        return new CommandPersistence($this->db)->record($context, new PreparedInternalCommand(
            $command->commandRef, $command->businessRef, self::OWNER_MODULE_ID,
            PreparedFactualCommand::COMMAND_TYPE, PreparedFactualCommand::COMMAND_SCHEMA, $body,
            [PreparedFactualCommand::DESTINATION],
        ), QualifiedCommandScope::encode($selected, $observation->sourceLineage, $observation->partitionRef, $command->businessRef,
            EventRows::scope($this->db->connection($context->connectionName()), $context)->lineage));
    }

    private static function sourceLineage(FinancialSessionView $session): string
    {
        return 'execution-lineage:'.hash('sha256', (new Codec)->canonicalize([
            'schema' => 'billium.execution.source-lineage.v1',
            'installationRef' => $session->installationRef,
            'ownerSessionRef' => $session->ownerSessionRef,
            'deploymentGeneration' => (string) $session->deploymentGeneration,
            'attachmentGeneration' => (string) $session->attachmentGeneration,
            'authorityEpoch' => (string) $session->authorityEpoch,
            'localSessionEpoch' => (string) $session->epoch,
        ]));
    }

    private static function partitionRef(PaymentObservation $observation, string $rootRef): string
    {
        return 'execution-charge:'.hash('sha256', (new Codec)->canonicalize([
            'schema' => 'billium.execution.observation-partition.v1',
            'installationRef' => $observation->installationRef,
            'sellerRef' => $observation->sellerRef,
            'environment' => $observation->environment,
            'sourceAuthority' => $observation->sourceAuthority,
            'sourceLineage' => $observation->sourceLineage,
            'rootOperationRef' => $rootRef,
        ]));
    }
}
