<?php

declare(strict_types=1);

namespace Billium\Core\Plugins;

use Billium\Ledger\Contracts\CorrectionCreditParticipants;
use Billium\Ledger\Contracts\CorrectionLocks;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationApplier;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Stateless Packman extension plugin composing Billium Ledger schema ownership and capability contracts.
 */
#[Api(since: '1.0')]
final class LedgerPlugin implements ExtensionPlugin, WithCapabilities, WithSchema, WithSlots
{
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.billium.ledger',
            version: '0.1.0',
            requires: ['axiomasoft.billium.platform'],
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function tablePrefix(): string
    {
        return 'billium_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return [
            'allocation_receipt',
            'invoice',
            'obligation',
            'adjustment',
            'customer_credit',
            'settlement_intent',
            'settlement_target',
            'settlement_intent_closure',
            'observed_payment',
            'observed_payment_revision',
            'payment',
            'payment_revision',
            'allocation',
            'invoice_line',
            'credit_note',
            'ledger_discrepancy',
            'discrepancy_event',
            'observation_park',
            'refund_reconcile_progress',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/ledger';
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            CorrectionCreditParticipants::class => Cardinality::OptionalSingle->value,
            CorrectionLocks::class => Cardinality::OptionalSingle->value,
            Ledger::class => Cardinality::OptionalSingle->value,
            LedgerParticipants::class => Cardinality::OptionalSingle->value,
            LedgerQueries::class => Cardinality::OptionalSingle->value,
            ObservationApplier::class => Cardinality::OptionalSingle->value,
        ];
    }
}
