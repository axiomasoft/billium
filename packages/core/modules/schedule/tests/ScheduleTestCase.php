<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests;

use Billium\Platform\Tests\PlatformTestCase;
use Billium\Schedule\Contracts\AgreementRevisionCommit;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Contracts\Clock;
use Billium\Schedule\Data\PeriodQuote;
use Billium\Schedule\Data\ScheduleReceipt;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Billium\Schedule\Laravel\ScheduleServiceProvider;
use Billium\Schedule\Tests\Support\FrozenClock;
use Billium\Schedule\Tests\Support\HostGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

abstract class ScheduleTestCase extends PlatformTestCase
{
    protected HostGuard $host;

    protected FrozenClock $clock;

    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ScheduleServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('billium.modules.enabled', ['platform', 'ledger', 'rating', 'schedule']);
        $app->make(Repository::class)->set('billium.rating.enabled', true);
        $app->make(Repository::class)->set('billium.schedule.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new HostGuard;
        $this->clock = new FrozenClock;
        $this->application()->instance(AgreementRevisionGuard::class, $this->host);
        $this->application()->instance(Clock::class, $this->clock);
        $this->activeSession();
    }

    protected function schedule(): AgreementRevisionCommit
    {
        return $this->application()->make(AgreementRevisionCommit::class);
    }

    protected function quote(ScheduleRevisionTerms $terms, int $ordinal = 0, string $key = 'preview-1'): PeriodQuote
    {
        $this->host->approved = $terms;

        return $this->schedule()->preview(terms: $terms, ordinal: $ordinal, validUntil: '2024-01-20T00:00:00Z', commandKey: $key);
    }

    protected function commitQuote(PeriodQuote $quote, string $key = 'commit-1'): ScheduleReceipt
    {
        return $this->schedule()->commit(quoteRef: $quote->quoteRef, quoteDigest: $quote->digest, expectedRevision: $quote->terms->agreementRevision, cancellationGeneration: $quote->terms->cancellationGeneration, commandKey: $key);
    }
}
