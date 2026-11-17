<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Integration\Support\P4DatabaseSnapshot;
use Billium\Core\Tests\Integration\Support\P4TaxPolicy;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingCorrection;
use Billium\Rating\Contracts\TaxCorrectionPolicyFrontier;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Correction\ApplyCorrection;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\Tier;
use Billium\Rating\Data\Pricing\VolumeTerms;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Enums\CorrectionKind;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Tests\RatingTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class P4AcceptanceReplayTest extends RatingTestCase
{
    /** @return array<string, array{string}> */
    public static function strategies(): array
    {
        return ['graduated' => ['graduated'], 'volume' => ['volume']];
    }

    #[DataProvider('strategies')]
    public function test_restore_permutation_and_late_revision_converge(string $strategy): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 4).'/fixtures/p4/acceptance-replay.json'), true, flags: JSON_THROW_ON_ERROR);
        $expected = $fixture['strategies'][$strategy];
        $this->tax->rate = $fixture['tax_rate'];
        $version = 1;
        if ($strategy === 'volume') {
            $fields = get_object_vars($this->price());
            $fields['version'] = 2;
            $fields['strategyId'] = StrategyId::TieredVolume;
            $fields['terms'] = new VolumeTerms(tiers: [new Tier(index: 0, lower: '0', upper: '150', unitPrice: '1', flatFee: '0'),
                new Tier(index: 1, lower: '150', upper: null, unitPrice: '0.8', flatFee: '0')]);
            $draft = new PriceVersion(...$fields);
            $fields['termsDigest'] = CanonicalJson::termsDigest($draft);
            $this->application()->make(PriceVersionStore::class)->publish(price: new PriceVersion(...$fields), commandKey: 'volume');
            $version = 2;
        }
        $prepared = $this->prepared(priceVersion: $version);
        self::assertSame(0, bccomp($fixture['original_quantity'], $prepared->inputs->aggregates[0]->quantity, 9));
        $booked = $this->book($prepared);
        self::assertSame($fixture['original_gross_minor'], $booked->grossMinor);
        $scope = new BillingAccountScope(sellerRef: 'seller-a', environment: 'test', billingAccountRef: 'original-account');
        $ledger = $this->application()->make(Ledger::class);
        self::assertTrue($ledger->createInvoice(new CreateInvoice(commandKey: 'replay-draft', scope: $scope, invoiceRef: 'replay-invoice',
            customerRef: 'original-customer', lines: [new InvoiceLineInput(obligationRef: $booked->obligationRef)]))->accepted);
        self::assertTrue($ledger->issueInvoice(new IssueInvoice(commandKey: 'replay-issue', scope: $scope, invoiceRef: 'replay-invoice'))->accepted);
        $invoice = $this->value("SELECT row_to_json(i)::text FROM billium_invoice i WHERE invoice_ref='replay-invoice'");
        $period = $this->value('SELECT row_to_json(o)::text FROM billium_schedule_billing_occurrence o');
        $backup = P4DatabaseSnapshot::capture($this->owner());
        $converged = null;
        $observations = [];
        foreach ([$fixture['late_facts'], array_reverse($fixture['late_facts'])] as $delivery) {
            $backup->restore($this->owner());
            self::assertEquals($backup, P4DatabaseSnapshot::capture($this->owner()));
            $beforeCounts = $this->counts();
            self::assertSame($fixture['counts']['original'], $beforeCounts);
            // Publishing a new current price must never change original-period correction meaning.
            $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 99, fixed: '999'), commandKey: 'future-price');
            $port = new P4TaxPolicy;
            $this->application()->instance(TaxSnapshotPort::class, $port);
            $command = new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef,
                kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'late', releasePolicy: null,
                correctionEffectiveDate: new DateTimeImmutable('2026-11-15Z'));
            foreach ($delivery as $input) {
                $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($raw) use ($input): void {
                    $raw->factId = $input['id'];
                    $raw->value->quantity = $input['quantity'];
                }));
            }
            $this->freeze($prepared->inputs->aggregates[0]->snapshotDigest);
            $correction = $this->application()->make(RatingCorrection::class);
            // Publish the host's exact versioned policy before financial application.
            $held = $correction->prepare($command);
            self::assertInstanceOf(RatingHold::class, $held);
            self::assertSame('billium.rating.tax_policy_stale', $held->detail);
            $publication = new TaxCorrectionRequest(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef,
                originalTaxSnapshotRef: 'original', originalTaxSnapshotDigest: 'digest', originalTaxPoint: $command->correctionEffectiveDate,
                correctionEffectiveDate: $command->correctionEffectiveDate, reasonCode: 'late', jurisdiction: 'TEST',
                originalTaxableBaseMinor: '10000', correctedTaxableBaseMinor: '14000', inputDigest: 'publication-input');
            $this->application()->make(TaxCorrectionPolicyFrontier::class)->publish(policy: $port->resolveCorrection($publication), commandKey: 'replay-policy');
            $first = $correction->prepare($command);
            self::assertInstanceOf(PreparedCorrection::class, $first);
            self::assertSame($expected['first_delta_gross_minor'], $first->computation->deltaGrossMinor);
            $receipt = $correction->apply(new ApplyCorrection(prepared: $first, operationKey: 'late-1'));
            self::assertSame('obligation', $receipt->outcome);
            $firstCounts = $this->counts();
            self::assertSame($fixture['counts']['first_correction'], $firstCounts);
            $beforeRevision = P4DatabaseSnapshot::capture($this->owner());
            $revision = $fixture['late_revision'];
            $late = UsageFixtures::fact(static function ($raw) use ($revision): void {
                $raw->factId = $revision['id'];
                $raw->value->quantity = $revision['previous_quantity'];
            });
            $this->usage()->submitUsageFact(UsageFixtures::correction(previous: $late, quantity: $revision['quantity']));
            $this->freeze($first->inputs->aggregates[0]->snapshotDigest);
            $second = $correction->prepare($command);
            self::assertInstanceOf(PreparedCorrection::class, $second);
            self::assertSame($first->computation->correctedBasis->basisRef, $second->computation->predecessorBasisRef);
            self::assertSame($expected['revision_delta_gross_minor'], $second->computation->deltaGrossMinor);
            $secondReceipt = $correction->apply(new ApplyCorrection(prepared: $second, operationKey: 'late-2'));
            self::assertSame($fixture['counts']['late_revision'], $this->counts());
            self::assertSame((int) $expected['effective_gross_minor'], $this->value('SELECT sum(amount_due_minor)::bigint FROM billium_obligation'));
            $lineage = $this->table('SELECT basis_ref, predecessor_basis_ref, net_minor, tax_minor, gross_minor, inputs_digest FROM billium_rating_rated_basis ORDER BY correction_revision NULLS FIRST');
            $converged ??= $lineage;
            self::assertSame($converged, $lineage);
            self::assertSame($invoice, $this->value("SELECT row_to_json(i)::text FROM billium_invoice i WHERE invoice_ref='replay-invoice'"));
            self::assertSame($period, $this->value('SELECT row_to_json(o)::text FROM billium_schedule_billing_occurrence o'));
            $afterRevision = P4DatabaseSnapshot::capture($this->owner());
            $beforeRevision->restore($this->owner());
            self::assertSame($fixture['counts']['first_correction'], $this->counts());
            $afterRevision->restore($this->owner());
            self::assertEquals($afterRevision, P4DatabaseSnapshot::capture($this->owner()));
            self::assertSame($secondReceipt->body(), $correction->apply(new ApplyCorrection(prepared: $second, operationKey: 'late-2'))->body());
            self::assertSame($fixture['counts']['late_revision'], $this->counts());
            self::assertSame($lineage, $this->table('SELECT basis_ref, predecessor_basis_ref, net_minor, tax_minor, gross_minor, inputs_digest FROM billium_rating_rated_basis ORDER BY correction_revision NULLS FIRST'));
            $observations[] = ['delivery' => array_column($delivery, 'id'), 'before' => $beforeCounts,
                'after_first_correction' => $firstCounts, 'counts' => $this->counts(),
                'first_receipt' => $receipt->body(), 'revision_receipt' => $secondReceipt->body(), 'lineage' => $lineage,
                'effective_gross_minor' => $this->value('SELECT sum(amount_due_minor)::bigint FROM billium_obligation')];
        }
        $this->record(name: $strategy, evidence: ['fixture' => $fixture['id'], 'expectation' => $expected,
            'observations' => $observations]);
    }

    public function test_fixed_fee_restore_reuses_frozen_price_tax_and_receipt(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 4).'/fixtures/p4/acceptance-replay.json'), true, flags: JSON_THROW_ON_ERROR);
        $fixed = $fixture['fixed'];
        $this->tax->rate = $fixed['tax_rate'];
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 2, fixed: $fixed['amount']), commandKey: 'fixed-price');
        $prepared = $this->prepared(priceVersion: 2);
        $backup = P4DatabaseSnapshot::capture($this->owner());
        $before = $this->counts();
        $first = $this->book($prepared);
        self::assertSame($fixed['gross_minor'], $first->grossMinor);
        $backup->restore($this->owner());
        self::assertEquals($backup, P4DatabaseSnapshot::capture($this->owner()));
        $this->tax->rate = '0.99';
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 3, fixed: '999'), commandKey: 'future-fixed-price');
        $replay = $this->book($prepared);
        self::assertSame($first->body(), $replay->body());
        self::assertSame($replay->body(), $this->book($prepared)->body());
        self::assertSame(['source' => 1, 'rated' => 1, 'obligation' => 1], $this->counts());
        $this->record(name: 'fixed', evidence: ['fixture' => $fixture['id'].'#fixed', 'expectation' => $fixed,
            'before' => $before, 'after' => $this->counts(), 'receipt' => $replay->body()]);
    }

    public function test_replayed_source_with_changed_payload_refuses_without_money(): void
    {
        $before = $this->counts();
        try {
            $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($raw): void {
                $raw->value->quantity = '101';
            }));
            self::fail('Changed payload reused an immutable source identity');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
        }
        self::assertSame($before, $this->counts());
    }

    /** @return array{source: int, rated: int, obligation: int} */
    private function counts(): array
    {
        return ['source' => $this->rows('billium_usage_fact'), 'rated' => $this->rows('billium_rating_rated_basis'),
            'obligation' => $this->rows('billium_obligation')];
    }

    /** @param array<string, mixed> $evidence */
    private function record(string $name, array $evidence): void
    {
        $directory = dirname(__DIR__, 4).'/.phpunit.cache/p4.10';
        if (! is_dir($directory)) {
            mkdir(directory: $directory, permissions: 0777, recursive: true);
        }
        $evidence['timestamp'] = gmdate('c');
        $evidence['php'] = PHP_VERSION;
        $evidence['postgresql'] = $this->value('SHOW server_version');
        self::assertNotFalse(file_put_contents($directory.'/replay-'.$name.'.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n"));
    }
}
