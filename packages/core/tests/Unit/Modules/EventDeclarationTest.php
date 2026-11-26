<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Modules;

use Billium\Core\Data\Modules\InternalCommandDeclaration;
use Billium\Core\Data\Modules\ModuleDescriptor;
use Billium\Core\Data\Modules\ModuleManifest;
use Billium\Core\Data\Modules\PublicEventDeclaration;
use Billium\Core\Enums\Modules\BootstrapMode;
use Billium\Core\Enums\Modules\CompatibilityFindingCode as Code;
use Billium\Platform\Data\EventContracts\EventContractConflict;
use Billium\Platform\Data\EventContracts\EventContractRegistration;
use Billium\Platform\Data\EventContracts\EventContractRegistrationPlan;
use InvalidArgumentException;

final class EventDeclarationTest extends ModuleTestCase
{
    private const string DIGEST = 'sha256:a2c799262a3ce3c19ef5cdd983bf3d12b43ab3c426227091b909dcb7054738c0';

    /** @return array<string, mixed> */
    private static function event(array $override = []): array
    {
        return [
            'eventType' => 'payment.effect.outcome.v1',
            'envelopeSchema' => 'billium.event.v2',
            'eventContractRevision' => 'events-2026-10-v2',
            'payloadSchemaRef' => 'spec/example.json#/payload',
            'payloadSchemaDigest' => self::DIGEST,
            'bootstrapMode' => 'snapshot',
            'snapshotSourceKey' => 'execution.effects',
            'retentionPolicyRef' => 'financial-permanent',
            ...$override,
        ];
    }

    private static function descriptor(string $moduleId, array ...$events): ModuleDescriptor
    {
        $manifest = ModuleManifest::fromArray(self::withModule(self::document(), $moduleId, ['publicEvents' => $events]));
        $descriptor = $manifest->descriptor($moduleId);
        self::assertNotNull($descriptor);

        return $descriptor;
    }

    /** @param array<string, mixed> $override */
    private static function assertRejected(array $override, string $message): void
    {
        try {
            PublicEventDeclaration::fromArray(self::event($override), 'test');
            self::fail("accepted {$message}");
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_ledger_streams_each_declare_a_snapshot_source_owned_by_ledger(): void
    {
        $ledger = self::catalog()->descriptor('ledger');
        self::assertNotNull($ledger);
        self::assertCount(7, $ledger->publicEvents);
        foreach ($ledger->publicEvents as $event) {
            self::assertSame('billium.event.v1', $event->envelopeSchema);
            self::assertSame(BootstrapMode::Snapshot, $event->bootstrapMode);
            self::assertStringStartsWith('ledger.', (string) $event->snapshotSourceKey);
            self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $event->payloadSchemaDigest);
        }
    }

    public function test_strict_v1_is_not_widened_but_a_negotiated_v2_revision_may_declare_a_new_type(): void
    {
        self::assertRejected(['envelopeSchema' => 'billium.event.v1', 'eventContractRevision' => 'events-2026-10-v1'], 'billium.event.v1 is strict');

        $v2 = PublicEventDeclaration::fromArray(self::event(), 'test');
        self::assertSame('billium.event.v2', $v2->envelopeSchema);
        self::assertCount(7, PublicEventDeclaration::STRICT_V1_TYPES);
        self::assertNotContains('payment.effect.outcome.v1', PublicEventDeclaration::STRICT_V1_TYPES);
    }

    public function test_public_stream_without_a_bootstrap_declaration_is_rejected(): void
    {
        $event = self::event();
        unset($event['bootstrapMode']);
        try {
            PublicEventDeclaration::fromArray($event, 'test');
            self::fail('accepted a stream without bootstrapMode');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('missing member bootstrapMode', $exception->getMessage());
        }

        $keyless = self::event();
        unset($keyless['snapshotSourceKey']);
        try {
            PublicEventDeclaration::fromArray($keyless, 'test');
            self::fail('accepted a snapshot stream without a SnapshotSource key');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('needs a SnapshotSource key', $exception->getMessage());
        }
        self::assertRejected(['bootstrapMode' => 'replay_only'], 'declares no SnapshotSource');
        self::assertRejected(['bootstrapMode' => 'streaming'], 'unknown bootstrapMode');

        $replay = self::event(['bootstrapMode' => 'replay_only']);
        unset($replay['snapshotSourceKey']);
        self::assertSame(BootstrapMode::ReplayOnly, PublicEventDeclaration::fromArray($replay, 'test')->bootstrapMode);
    }

    public function test_payload_schema_digest_and_revision_are_validated(): void
    {
        self::assertRejected(['payloadSchemaDigest' => 'md5:abc'], 'payloadSchemaDigest');
        self::assertRejected(['payloadSchemaDigest' => 'sha256:ABC'], 'payloadSchemaDigest');
        self::assertRejected(['payloadSchemaRef' => ''], 'payloadSchemaRef');
        self::assertRejected(['eventContractRevision' => 'events-2026-10-v1'], 'eventContractRevision');
        self::assertRejected(['eventType' => 'Payment Outcome'], 'invalid eventType');
    }

    public function test_duplicate_events_in_one_descriptor_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate public event');
        self::descriptor('execution', self::event(), self::event());
    }

    public function test_changed_contract_declared_by_two_modules_is_a_finding(): void
    {
        $catalog = self::catalog(fn (array $document): array => self::withModule(
            self::withModule($document, 'execution', ['publicEvents' => [self::event()]]),
            'collection',
            ['publicEvents' => [self::event(['payloadSchemaDigest' => 'sha256:'.str_repeat('1', 64)])]],
        ));

        self::assertTrue($catalog->check(self::input('embedded-commerz', ['platform', 'ledger']))->has(Code::EventContractConflict, 'collection'));
    }

    public function test_internal_commands_stay_on_their_own_lane(): void
    {
        $command = ['commandType' => 'collection.execute.v1', 'commandSchema' => 'billium.command.v1', 'payloadSchemaRef' => 'spec/example.json#/command', 'payloadSchemaDigest' => self::DIGEST];
        $parsed = InternalCommandDeclaration::fromArray($command, 'test');
        self::assertSame('billium.command.v1', $parsed->commandSchema);

        foreach ([
            ['must be billium.command.vN', ['commandSchema' => 'billium.event.v1']],
            ['must be billium.command.vN', ['commandSchema' => 'billium.event.v2']],
            ['sha256 digest are required', ['payloadSchemaDigest' => 'nope']],
        ] as [$reason, $broken]) {
            try {
                InternalCommandDeclaration::fromArray([...$command, ...$broken], 'test');
                self::fail('accepted '.json_encode($broken));
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString($reason, $error->getMessage());
            }
        }

        $manifest = ModuleManifest::fromArray(self::withModule(self::document(), 'collection', ['internalCommands' => [$command]]));
        $collection = $manifest->descriptor('collection');
        self::assertNotNull($collection);
        self::assertSame([], $collection->publicEvents, 'a command never becomes a public event');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both a public event and an internal command');
        ModuleManifest::fromArray(self::withModule(self::document(), 'collection', [
            'internalCommands' => [[...$command, 'commandType' => 'payment.effect.outcome.v1']],
            'publicEvents' => [self::event()],
        ]));
    }

    public function test_registration_plan_registers_new_contracts_for_an_enabled_module(): void
    {
        $descriptor = self::descriptor('execution', self::event());
        $plan = EventContractRegistrationPlan::between($descriptor, [], ['execution'], true);

        self::assertTrue($plan->isApplicable());
        self::assertCount(1, $plan->toRegister);
        self::assertSame('execution', $plan->toRegister[0]->ownerModuleId);
        self::assertSame('events-2026-10-v2|billium.event.v2|payment.effect.outcome.v1', $plan->toRegister[0]->key());
    }

    public function test_registration_plan_rejects_unknown_owner_and_changed_contract(): void
    {
        $descriptor = self::descriptor('execution', self::event());

        $unknown = EventContractRegistrationPlan::between($descriptor, [], ['ledger'], true);
        self::assertFalse($unknown->isApplicable());
        self::assertSame(EventContractConflict::OWNER_UNKNOWN, $unknown->conflicts[0]->reason);

        $registered = EventContractRegistration::from('execution', PublicEventDeclaration::fromArray(self::event(['payloadSchemaDigest' => 'sha256:'.str_repeat('2', 64)]), 'test'));
        $changed = EventContractRegistrationPlan::between($descriptor, [$registered], ['execution'], true);
        self::assertSame(EventContractConflict::CHANGED_CONTRACT, $changed->conflicts[0]->reason);
        self::assertSame([], $changed->toRegister);

        $foreignOwner = EventContractRegistration::from('collection', PublicEventDeclaration::fromArray(self::event(), 'test'));
        self::assertFalse(EventContractRegistrationPlan::between($descriptor, [$foreignOwner], ['execution', 'collection'], true)->isApplicable());
    }

    public function test_disabled_fresh_module_has_no_rows_and_used_disabled_module_keeps_its_history(): void
    {
        $descriptor = self::descriptor('execution', self::event());

        $fresh = EventContractRegistrationPlan::between($descriptor, [], ['execution'], false);
        self::assertTrue($fresh->isApplicable());
        self::assertSame([], $fresh->toRegister);
        self::assertSame([], $fresh->unchanged);

        $historical = new EventContractRegistration(
            'execution', 'events-2026-10-v2', 'billium.event.v2', 'payment.effect.outcome.v1', 'spec/example.json#/payload',
            self::DIGEST, BootstrapMode::Snapshot, 'execution.effects', 'financial-permanent', EventContractRegistration::HISTORICAL,
        );
        $kept = EventContractRegistrationPlan::between($descriptor, [$historical], ['execution'], false);
        self::assertTrue($kept->isApplicable());
        self::assertSame([$historical], $kept->unchanged);
        self::assertSame([], $kept->toRegister);
    }

    public function test_unknown_registration_status_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EventContractRegistration('execution', 'events-2026-10-v2', 'billium.event.v2', 'x.v1', 'r', self::DIGEST, BootstrapMode::ReplayOnly, null, 'financial-permanent', 'deleted');
    }
}
