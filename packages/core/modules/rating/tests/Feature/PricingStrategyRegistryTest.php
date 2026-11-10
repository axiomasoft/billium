<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\CallerContext;
use Billium\Core\Data\Caller;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Tests\PlatformTestCase;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Contracts\TaxSnapshotStore;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Data\Tax\TaxResolutionRequest;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Enums\TaxMode;
use Billium\Rating\Enums\TaxSnapshotKind;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\RatingServiceProvider;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Throwable;

final class PricingStrategyRegistryTest extends PlatformTestCase
{
    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), RatingServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('billium.modules.enabled', ['platform', 'ledger', 'rating']);
        $app->make(Repository::class)->set('billium.rating.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->callers->set(new Caller(sellerRef: 'seller-a', environment: 'test', installationRef: self::INSTALLATION, actorRef: 'rating-test'));
        $this->application()->instance(CallerContext::class, $this->callers);
        $this->installation()->init(self::INSTALLATION);
    }

    private function price(string $amount): PriceVersion
    {
        $args = ['sellerRef' => 'seller-a', 'priceRef' => 'monthly', 'version' => 1, 'strategyId' => StrategyId::Fixed, 'strategyVersion' => 1, 'currency' => 'USD', 'currencyExponent' => 2, 'exponentVersion' => 'ISO4217-2026-10', 'unit' => 'component', 'quantityRules' => new QuantityRules(quantityScale: 0, conversionNumerator: '1', conversionDenominator: '1', billingIncrement: '1', incrementMode: IncrementMode::None), 'terms' => new FixedTerms(amount: $amount, scope: 'per_component_occurrence'), 'roundingMode' => RoundingMode::HalfUp, 'roundingStage' => RoundingStage::AfterTax, 'roundingPolicyVersion' => 'test', 'commitment' => null];
        $draft = FixtureArguments::construct(class: PriceVersion::class, arguments: [...$args, 'termsDigest' => 'draft']);

        return FixtureArguments::construct(class: PriceVersion::class, arguments: [...$args, 'termsDigest' => CanonicalJson::termsDigest($draft)]);
    }

    public function test_pricing_strategy_publication_is_atomic_immutable_and_replay_safe(): void
    {
        $store = $this->application()->make(PriceVersionStore::class);
        $price = $this->price('49.00');
        $first = $store->publish(price: $price, commandKey: 'publish-1');
        self::assertSame($first, $store->publish(price: $price, commandKey: 'publish-1'));
        self::assertSame($first, $store->publish(price: $price, commandKey: 'publish-2'));
        self::assertEquals($price, $store->find(priceRef: 'monthly', version: 1));
        self::assertSame(1, $this->rows('billium_rating_price_version'));
        self::assertSame(1, $this->rows('billium_rating_price_publication'));
        try {
            $store->publish(price: $this->price('50.00'), commandKey: 'publish-conflict');
            self::fail('changed immutable price accepted');
        } catch (ProblemException $exception) {
            self::assertStringContainsString('billium.rating.price_version_conflict', $exception->detail);
        }
        try {
            $store->publish(price: $this->price('50.00'), commandKey: 'publish-1');
            self::fail('idempotency key with a different payload was accepted');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::IdempotencyConflict, $exception->problem);
        }
        self::assertSame('55000', self::sqlState($this->owner()->getPdo(), "UPDATE billium_rating_price_version SET price_ref='changed'"));
        self::assertSame('55000', self::sqlState($this->owner()->getPdo(), 'DELETE FROM billium_rating_price_version'));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(), 'UPDATE billium_rating_price_publication SET publication_key=publication_key'));
        self::assertSame(1, $this->rows('billium_rating_price_version'));
    }

    public function test_pricing_strategy_deferred_constraint_refuses_tiers_added_to_fixed_price(): void
    {
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price('49.00'), commandKey: 'publish-1');
        $db = $this->owner();
        try {
            $db->transaction(function () use ($db): void {
                $db->statement('INSERT INTO billium_rating_price_tier (price_version_id,tier_index,lower_bound,upper_bound,unit_price,flat_fee) SELECT id,0,0,NULL,1,0 FROM billium_rating_price_version');
            });
            self::fail('tier on fixed price was committed');
        } catch (Throwable $exception) {
            self::assertStringContainsString('billium.rating.terms_invalid', $exception->getMessage());
        }
        self::assertSame(0, $this->rows('billium_rating_price_tier'));
    }

    public function test_pricing_strategy_tax_resolution_freezes_evidence_and_missing_rate_rolls_back(): void
    {
        $host = new class implements TaxSnapshotPort
        {
            public bool $missing = false;

            public function resolve(TaxResolutionRequest $request): TaxSnapshot
            {
                if ($this->missing) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.tax_rate_missing');
                }

                return new TaxSnapshot(snapshotRef: 'test-tax', kind: TaxSnapshotKind::Standard, mode: TaxMode::Exclusive, jurisdiction: 'TEST', taxCode: 'TEST', rate: '0.2000', effectiveDate: new DateTimeImmutable('2026-01-01'), taxPoint: $request->taxPoint, noTaxReason: null, resolver: 'test-host', resolverVersion: '1', inputDigest: $request->inputDigest, evidenceDigest: 'test-evidence');
            }

            public function resolveCorrection(TaxCorrectionRequest $request): TaxCorrectionPolicySnapshot
            {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.tax_policy_missing');
            }
        };
        $this->application()->instance(TaxSnapshotPort::class, $host);
        $store = $this->application()->make(TaxSnapshotStore::class);
        $request = new TaxResolutionRequest(sellerRef: 'seller-a', environment: 'test', billingAccountRef: 'customer', jurisdictionHint: 'TEST', componentRef: 'api', currency: 'USD', taxPoint: new DateTimeImmutable('2026-02-01'), inputDigest: 'input');
        $host->missing = true;
        try {
            $store->freeze(snapshot: $host->resolve($request), commandKey: 'missing');
            self::fail('missing tax evidence accepted');
        } catch (ProblemException $exception) {
            self::assertStringContainsString('billium.rating.tax_rate_missing', $exception->detail);
        }
        self::assertSame(0, $this->rows('billium_rating_tax_snapshot'));
        $host->missing = false;
        $snapshot = $host->resolve($request);
        $store->freeze(snapshot: $snapshot, commandKey: 'freeze');
        self::assertSame('0.2000', $snapshot->rate);
        self::assertSame('0.2000', $this->value('SELECT rate_text FROM billium_rating_tax_snapshot'));
        self::assertSame('test-evidence', $this->value('SELECT evidence_digest FROM billium_rating_tax_snapshot'));
        self::assertSame('55000', self::sqlState($this->owner()->getPdo(), 'DELETE FROM billium_rating_tax_snapshot'));
    }
}
