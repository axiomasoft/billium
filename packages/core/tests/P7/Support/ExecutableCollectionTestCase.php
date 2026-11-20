<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7\Support;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\OpenDunningCase;
use Billium\Collection\Data\RegisterDunningPolicyVersion;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Data\CapabilityDetails;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use DateTimeImmutable;

abstract class ExecutableCollectionTestCase extends CollectionTestCase
{
    protected const ?int LOOKUP_RETENTION_SECONDS = null;

    protected string $journal;

    protected CapabilitySnapshot $capability;

    protected function setUp(): void
    {
        parent::setUp();
        $app = $this->application();
        $this->journal = Fixture::tempPath();
        $base = SimulatedCapabilities::snapshot();
        $details = new CapabilityDetails(...array_replace(get_object_vars($base->details), [
            'savedMethod' => true, 'recurringConsent' => true,
            'lookupRetentionSeconds' => static::LOOKUP_RETENTION_SECONDS ?? $base->details->lookupRetentionSeconds,
        ]));
        $this->capability = new CapabilitySnapshot(...array_replace(get_object_vars($base), [
            'snapshotRef' => 'collection-saved-method', 'digest' => self::digest('c'), 'details' => $details,
        ]));
        Fixture::seedMerchant($app, $this->capability);
        Fixture::host($app, $this->journal, cap: $this->capability);
        $app->instance(CollectionConsentGuard::class, new CollectionAdmissionScenario);
        $merchant = $app->make(MerchantRegistry::class)->forAdmission(Fixture::scope(), 'merchant-a');
        $this->consentService()->registerBinding($this->registerCommand(
            merchantAccountRef: 'account-a', merchantConfigRevision: 'merchant-a', currencies: ['RUB'], validFrom: new DateTimeImmutable('2026-11-20T00:00:00Z'),
        ), $merchant, $this->capability);
        $this->dunningPolicyService()->registerPolicyVersion(new RegisterDunningPolicyVersion(
            'policy', $this->collectionScope(), 'policy-a', 1, [CollectionChannel::SavedMethod],
            [new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 0)],
            1, 3, [DeclineCategory::SoftDecline], 86400,
        ));
        $this->application()->make(Ledger::class)->recordObligation(new RecordObligation(
            'obligation', $this->account(), 'obligation-a', 'basis-a', 'customer-a', 'component-a', $this->money(5000), self::digest(),
        ))->orThrow();
        $this->openCase();
    }

    protected function tearDown(): void
    {
        if (isset($this->journal)) {
            UnknownResultTest::clean($this->journal);
        }
        parent::tearDown();
    }

    protected function openCase(string $ref = 'case-a', int $generation = 1): void
    {
        $this->dunningCaseService()->openCase(new OpenDunningCase('open-'.$ref, $this->collectionScope(), $ref, 'account-a',
            'obligation-a', $generation, 'policy-a', 1, 'RUB', 5000, openedAt: new DateTimeImmutable('-1 hour')));
    }

    protected function account(): BillingAccountScope
    {
        return new BillingAccountScope('seller-a', 'test', 'account-a');
    }

    protected function money(int $minor): Money
    {
        return new Money($minor, Currency::of('RUB'));
    }

    protected function allocate(int $minor): void
    {
        $ledger = $this->application()->make(Ledger::class);
        $ledger->recordCustomerCredit(new RecordCustomerCredit('credit', $this->account(), 'credit-a', 'incoming-a', $this->money($minor)))->orThrow();
        $ledger->allocate(new Allocate('allocation', $this->account(), 'allocation-a', 'obligation-a', $this->money($minor), customerCreditRef: 'credit-a'))->orThrow();
    }

    protected function commandFromOutbox(): ExecutePayment
    {
        $payload = $this->value("SELECT payload->'payment' FROM billium_outbox_command WHERE command_type = 'collection.execute.v1'");
        self::assertIsString($payload);
        $command = (new Codec)->decode($payload);
        self::assertInstanceOf(ExecutePayment::class, $command);

        return $command;
    }

    protected function assertRefused(ProblemCode $problem, callable $action): void
    {
        try {
            $action();
            self::fail('Expected financial refusal');
        } catch (ProblemException $e) {
            self::assertSame($problem, $e->problem, $e->detail);
        }
    }
}
