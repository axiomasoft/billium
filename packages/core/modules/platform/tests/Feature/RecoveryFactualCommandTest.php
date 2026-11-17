<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Modules\InternalCommandDeclaration;
use Billium\Core\Data\Modules\ModuleDescriptor;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Contracts\Outbox\FactualCommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedFactualCommand;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Billium\Platform\Internal\CommandOutbox\FactualCommandRecorder;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
#[Group('COV-515')]
final class RecoveryFactualCommandTest extends ExecutionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', ['standalone-billium'], ['source-a']));
    }

    public function test_active_and_recovery_fact_recording_use_one_stable_immutable_command_route(): void
    {
        foreach ([FinancialMode::Active, FinancialMode::Recovery] as $mode) {
            if ($mode === FinancialMode::Recovery) {
                $this->recovery();
            }
            $command = $this->command('command-'.$mode->value);
            $this->runner()->run($this->spec(financialScope: self::selection(), mode: $mode, class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($command): Receipt {
                $id = $this->outbox()->record($ctx, $command);
                self::assertSame($id, $this->outbox()->record($ctx, $command));
                self::assertGreaterThan(0, $id);

                return self::receipt();
            });
        }
        self::assertSame(2, $this->rows('billium_outbox_command'));
        self::assertSame(2, $this->rows('billium_outbox_command_delivery', 'destination_ref = ?', [PreparedFactualCommand::DESTINATION]));
        self::assertSame(0, $this->rows('billium_outbox_event'));
        self::assertSame(0, $this->rows('billium_outbox_command', "owner_module_id <> 'execution' OR command_schema <> 'billium.command.v1' OR command_type <> 'payment.observation.v1'"));
    }

    public function test_all_nonfactual_classes_and_all_provider_effect_commands_still_refuse_in_recovery(): void
    {
        $this->recovery();
        foreach (ModuleOperationClass::cases() as $class) {
            if ($class === ModuleOperationClass::ObservedFactRecording) {
                continue;
            }
            try {
                $this->runner()->run($this->spec(financialScope: self::selection(), mode: FinancialMode::Recovery, class: $class, modules: ['execution', 'platform']), function (TransactionContext $ctx) {
                    $this->outbox()->record($ctx, $this->command());

                    return self::receipt();
                });
                self::fail('Nonfactual class recorded a command');
            } catch (LogicException|ProblemException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        foreach (['execute', 'authorize', 'capture', 'void_authorization', 'refund'] as $effect) {
            foreach ([ModuleOperationClass::NewIntent, ModuleOperationClass::UnknownResolution, ModuleOperationClass::ObservedFactRecording] as $class) {
                try {
                    $this->runner()->run($this->spec(financialScope: self::selection(), mode: FinancialMode::Recovery, class: $class, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($effect) {
                        $this->application()->make(CommandOutbox::class)->record($ctx, new PreparedInternalCommand('effect-'.$effect, 'root-a', 'execution', 'payment.'.$effect.'.v1', 'billium.command.v1', [], [PreparedFactualCommand::DESTINATION]));

                        return self::receipt();
                    });
                    self::fail('Recovery outgoing effect was admitted');
                } catch (LogicException $exception) {
                    self::assertStringContainsString('Outbox recording requires', $exception->getMessage());
                }
            }
        }
        self::assertSame(0, $this->rows('billium_outbox_command'));
        self::assertSame(0, $this->rows('billium_outbox_command_delivery'));
    }

    public function test_scope_lineage_partition_digest_and_raw_schema_are_revalidated_before_insertion(): void
    {
        foreach (['sellerRef' => 'foreign', 'environment' => 'production', 'installationRef' => 'clone', 'sourceAuthority' => 'remote-billium', 'sourceLineage' => 'retired-owner', 'partitionRef' => 'different-root'] as $field => $value) {
            $command = $this->command(overrides: [$field => $value]);
            try {
                $this->record($command);
                self::fail($field.' substitution admitted');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::AuthDenied, $exception->problem);
            }
        }
        foreach (['contentDigest', 'schema'] as $field) {
            $command = $this->command();
            $command->observation->raw->{$field} = $field === 'schema' ? 'payment.execute.v1' : 'sha256:'.str_repeat('f', 64);
            try {
                $this->record($command);
                self::fail('Mutated raw payload admitted');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::ValidationInvalid, $exception->problem);
            }
        }
        self::assertSame(0, $this->rows('billium_outbox_command'));
    }

    public function test_rollback_removes_fact_delivery_and_owner_effect_and_changed_content_conflicts(): void
    {
        $command = $this->command();
        try {
            $this->runner()->run($this->spec(financialScope: self::selection(), key: 'rollback-fact', class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($command) {
                self::effect($ctx, 'rollback-effect');
                $this->outbox()->record($ctx, $command);
                throw new RuntimeException('fault-after-command');
            });
            self::fail('Fault absent');
        } catch (RuntimeException $exception) {
            self::assertSame('fault-after-command', $exception->getMessage());
        }
        foreach (['billium_outbox_command', 'billium_outbox_command_delivery', 'billium_test_effect', 'billium_money_commit_log'] as $table) {
            self::assertSame(0, $this->rows($table));
        }
        $this->record($command);
        try {
            $this->record($this->command(overrides: ['evidenceRef' => 'changed-evidence']));
            self::fail('Changed command body accepted');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::IdempotencyConflict, $exception->problem);
        }
        self::assertSame(1, $this->rows('billium_outbox_command'));
        self::assertSame(1, $this->rows('billium_outbox_command_delivery'));
    }

    public function test_foreign_stale_and_incomplete_contexts_never_record_and_owner_declaration_is_required(): void
    {
        $stale = null;
        $this->runner()->run($this->spec(financialScope: self::selection(), class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform']), function (TransactionContext $ctx) use (&$stale) {
            $stale = $ctx;

            return self::receipt();
        });
        self::assertInstanceOf(TransactionContext::class, $stale);
        foreach ([$stale, $this->createMock(TransactionContext::class)] as $ctx) {
            try {
                $this->outbox()->record($ctx, $this->command());
                self::fail('Invalid context accepted');
            } catch (LogicException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        try {
            $this->runner()->run($this->spec(financialScope: self::selection(), class: ModuleOperationClass::ObservedFactRecording, modules: ['ledger', 'platform']), function (TransactionContext $ctx) {
                $this->outbox()->record($ctx, $this->command());

                return self::receipt();
            });
            self::fail('Omitted execution accepted');
        } catch (LogicException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        $registry = $this->createMock(ModuleRegistry::class);
        $registry->method('descriptor')->willReturn(null);
        $outbox = new FactualCommandRecorder($this->application()->make(DatabaseManager::class), $registry, fn () => $this->runner());
        try {
            $this->runner()->run($this->spec(financialScope: self::selection(), class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($outbox) {
                $outbox->record($ctx, $this->command());

                return self::receipt();
            });
            self::fail('Missing declaration accepted');
        } catch (LogicException $exception) {
            self::assertStringContainsString('Exact factual command contract', $exception->getMessage());
        }
        self::assertSame(0, $this->rows('billium_outbox_command'));
    }

    public function test_active_new_intent_and_frozen_destination_substitution_refuse(): void
    {
        try {
            $this->runner()->run($this->spec(financialScope: self::selection(), modules: ['execution', 'platform']), function (TransactionContext $ctx) {
                $this->outbox()->record($ctx, $this->command());

                return self::receipt();
            });
            self::fail('Active NewIntent admitted the factual lane');
        } catch (LogicException $exception) {
            self::assertStringContainsString('observed-fact root', $exception->getMessage());
        }
        $command = $this->command();
        $this->runner()->run($this->spec(financialScope: self::selection(), modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($command) {
            $this->application()->make(CommandOutbox::class)->record($ctx, new PreparedInternalCommand(
                $command->commandRef, $command->businessRef, 'execution', 'payment.observation.v1', 'billium.command.v1',
                get_object_vars($command->observation->raw), ['wrong-destination'],
            ));

            return self::receipt();
        });
        try {
            $this->record($command);
            self::fail('Frozen destination substituted');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::IdempotencyConflict, $exception->problem);
        }
        self::assertSame(1, $this->rows('billium_outbox_command_delivery', "destination_ref = 'wrong-destination'"));
        self::assertSame(0, $this->rows('billium_outbox_command_delivery', 'destination_ref = ?', [PreparedFactualCommand::DESTINATION]));
    }

    public function test_exact_owner_schema_type_reference_and_digest_are_pinned(): void
    {
        $descriptor = $this->application()->make(ModuleRegistry::class)->descriptor('execution');
        self::assertNotNull($descriptor);
        $declaration = $descriptor->internalCommands[0];
        foreach (['commandType' => 'payment.execute.v1', 'commandSchema' => 'billium.command.v2', 'payloadSchemaRef' => 'foreign.json', 'payloadSchemaDigest' => 'sha256:'.str_repeat('f', 64)] as $field => $value) {
            $wrong = new InternalCommandDeclaration(...array_replace(get_object_vars($declaration), [$field => $value]));
            $registry = $this->createMock(ModuleRegistry::class);
            $registry->method('descriptor')->willReturn(new ModuleDescriptor(...array_replace(get_object_vars($descriptor), ['internalCommands' => [$wrong]])));
            $outbox = new FactualCommandRecorder($this->application()->make(DatabaseManager::class), $registry, fn () => $this->runner());
            try {
                $this->runner()->run($this->spec(financialScope: self::selection(), class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($outbox) {
                    $outbox->record($ctx, $this->command());

                    return self::receipt();
                });
                self::fail('Unpinned contract admitted');
            } catch (LogicException $exception) {
                self::assertStringContainsString('Exact factual command contract', $exception->getMessage());
            }
        }
        self::assertSame(0, $this->rows('billium_outbox_command'));
    }

    public function test_maximum_length_root_identity_has_a_bounded_stable_partition(): void
    {
        $root = str_repeat('r', 200);
        $command = $this->command(rootRef: $root);
        self::assertSame(81, strlen($command->observation->partitionRef));
        self::assertSame($command->observation->partitionRef, $this->command(rootRef: $root)->observation->partitionRef);
        $this->record($command);
        self::assertSame($root, $this->value('SELECT business_ref FROM billium_outbox_command'));
    }

    public function test_reusing_owner_reference_with_new_generations_changes_lineage_and_refuses_old_body(): void
    {
        $old = $this->command();
        $this->authority->issueTransition('seal', 'lineage-seal', [1]);
        $this->installation()->seal('lineage-seal', 1);
        $this->authority->issueTransition('open', 'lineage-open', ['owner-1', 2, 2, 2, 'retired-old-generations']);
        $this->installation()->openSession('lineage-open', 'owner-1', 2, 2, 2, 'retired-old-generations');
        $token = FixedSessionAdmission::token(self::INSTALLATION, ownerSessionRef: 'owner-1', deploymentGeneration: 2, attachmentGeneration: 2, authorityEpoch: 2, modes: [FinancialMode::Recovery]);
        $this->tokens->set($token);
        $this->authority->issueProcess($token);
        $new = $this->command();
        self::assertNotSame($old->observation->sourceLineage, $new->observation->sourceLineage);
        self::assertNotSame($old->observation->partitionRef, $new->observation->partitionRef);
        foreach ([$old, $new] as $command) {
            try {
                $this->runner()->run($this->spec(financialScope: self::selection(), mode: FinancialMode::Recovery, class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($command) {
                    $this->outbox()->record($ctx, $command);

                    return self::receipt();
                });
                self::assertSame($new, $command);
            } catch (ProblemException $exception) {
                self::assertSame($old, $command);
                self::assertSame(ProblemCode::AuthDenied, $exception->problem);
            }
        }
        self::assertSame(1, $this->rows('billium_outbox_command'));
    }

    public function test_fact_source_must_equal_selected_tuple_even_when_caller_has_both_source_capabilities(): void
    {
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', ['standalone-billium', 'remote-billium'], ['source-a', 'unrelated-owner']));
        $command = $this->command();
        foreach ([null, new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium')] as $selection) {
            try {
                $spec = $this->spec(financialScope: $selection, class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform']);
                if ($selection === null) {
                    $spec = new OperationSpec(...array_replace(get_object_vars($spec), ['financialScope' => null]));
                }
                $this->runner()->run($spec, function (TransactionContext $ctx) use ($command) {
                    $this->outbox()->record($ctx, $command);

                    return self::receipt();
                });
                self::fail('Missing or different selected fact source admitted');
            } catch (ProblemException $exception) {
                self::assertSame(ProblemCode::AuthDenied, $exception->problem);
            }
        }
        self::assertSame(0, $this->rows('billium_outbox_command'));
        $this->record($command);
        self::assertSame(1, $this->rows('billium_outbox_command'));
    }

    private function outbox(): FactualCommandOutbox
    {
        return $this->application()->make(FactualCommandOutbox::class);
    }

    private function record(PreparedFactualCommand $command): void
    {
        $this->runner()->run($this->spec(financialScope: self::selection(), class: ModuleOperationClass::ObservedFactRecording, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($command) {
            $this->outbox()->record($ctx, $command);

            return self::receipt();
        });
    }

    /** @param array<string,mixed> $overrides */
    private function command(string $ref = 'command-a', array $overrides = [], string $rootRef = 'root-a'): PreparedFactualCommand
    {
        $session = $this->installation()->currentSession(self::INSTALLATION);
        self::assertNotNull($session);
        $lineage = 'execution-lineage:'.hash('sha256', (new Codec)->canonicalize([
            'schema' => 'billium.execution.source-lineage.v1', 'installationRef' => $session->installationRef,
            'ownerSessionRef' => $session->ownerSessionRef, 'deploymentGeneration' => (string) $session->deploymentGeneration,
            'attachmentGeneration' => (string) $session->attachmentGeneration, 'authorityEpoch' => (string) $session->authorityEpoch,
            'localSessionEpoch' => (string) $session->epoch,
        ]));
        $partition = 'execution-charge:'.hash('sha256', (new Codec)->canonicalize(['schema' => 'billium.execution.observation-partition.v1', 'installationRef' => self::INSTALLATION, 'sellerRef' => 'seller-a', 'environment' => 'test', 'sourceAuthority' => 'standalone-billium', 'sourceLineage' => $lineage, 'rootOperationRef' => $rootRef]));
        $raw = ObservationFactory::charge($rootRef, 1, 100, fields: ['sourceAuthority' => 'standalone-billium', 'sourceLineage' => $lineage, 'partitionRef' => $partition, ...$overrides]);
        $observation = (new Codec)->decode(ObservationFactory::json($raw));
        self::assertInstanceOf(PaymentObservation::class, $observation);

        return new PreparedFactualCommand($ref, $rootRef, $observation);
    }

    private function recovery(): void
    {
        $this->authority->issueTransition('seal', 'fact-seal', [1]);
        $this->installation()->seal('fact-seal', 1);
        $this->authority->issueTransition('open', 'fact-open', ['owner-2', 2, 2, 2, 'fact-retirement']);
        $this->installation()->openSession('fact-open', 'owner-2', 2, 2, 2, 'fact-retirement');
        $token = FixedSessionAdmission::token(self::INSTALLATION, ownerSessionRef: 'owner-2', deploymentGeneration: 2, attachmentGeneration: 2, authorityEpoch: 2, modes: [FinancialMode::Recovery]);
        $this->tokens->set($token);
        $this->authority->issueProcess($token);
    }

    private static function selection(): FinancialScope
    {
        return new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'standalone-billium');
    }
}
