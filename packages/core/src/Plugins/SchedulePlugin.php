<?php

declare(strict_types=1);

namespace Billium\Core\Plugins;

use Billium\Rating\Laravel\RatingCapabilities;
use Billium\Schedule\Contracts\AgreementRevisionCommit;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Contracts\Clock;
use Billium\Schedule\Contracts\CommercialPeriods;
use Billium\Schedule\Contracts\CorrectionParticipants;
use Billium\Schedule\Contracts\IntervalResolver;
use Billium\Schedule\Contracts\OccurrenceLocks;
use Billium\Schedule\Contracts\OccurrenceParticipants;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Contracts\ScheduleQueries;
use Billium\Schedule\Laravel\ScheduleCapabilities;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Stateless Packman extension plugin composing Billium Schedule schema ownership and capability contracts.
 */
#[Api(since: '1.0')]
final class SchedulePlugin implements ExtensionPlugin, WithCapabilities, WithSchema
{
    use OwnsSchema;
    use ProvidesCapabilities;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.billium.schedule',
            version: '0.1.0',
            requires: [
                'axiomasoft.billium.platform',
                'axiomasoft.billium.rating',
            ],
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
            'schedule_component',
            'schedule_quote',
            'schedule_revision',
            'schedule_period',
            'schedule_billing_schedule',
            'schedule_billing_schedule_revision',
            'schedule_billing_occurrence',
            'schedule_occurrence_amendment',
            'schedule_occurrence_outbox',
            'schedule_correction_head',
            'schedule_billing_correction',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/schedule';
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            OccurrenceLocks::class => Cardinality::OptionalSingle->value,
            OccurrenceService::class => Cardinality::OptionalSingle->value,
            OccurrenceParticipants::class => Cardinality::OptionalSingle->value,
            CorrectionParticipants::class => Cardinality::OptionalSingle->value,
            ScheduleQueries::class => Cardinality::OptionalSingle->value,
            IntervalResolver::class => Cardinality::OptionalSingle->value,
            AgreementRevisionCommit::class => Cardinality::OptionalSingle->value,
            CommercialPeriods::class => Cardinality::OptionalSingle->value,
            AgreementRevisionGuard::class => Cardinality::OptionalSingle->value,
            Clock::class => Cardinality::OptionalSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            ...ScheduleCapabilities::map(),
            ...RatingCapabilities::scheduled(),
        ];
    }
}
