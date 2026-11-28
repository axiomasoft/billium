<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\SpecRevision;
use Billium\Contracts\Data\Envelopes\CapabilitiesV2;
use Billium\Contracts\Data\Envelopes\PriceVersion;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

/**
 * @param  array<string, mixed>  $payload
 */
function signEnvelope(Codec $codec, array $payload): string
{
    $payload['digest'] = $codec->digest($payload);

    return $codec->canonicalize($payload);
}

function tokenFactV2Payload(string $quantity = '1000'): array
{
    return [
        'schema' => 'usage.fact.v2',
        'sellerRef' => 'seller-1',
        'source' => 'tariffler',
        'factId' => 'fact-token-1',
        'revision' => 1,
        'metricRef' => 'llm_tokens',
        'metricVersion' => 'llm_tokens/1',
        'metricDigest' => 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
        'unit' => 'token',
        'attribution' => [
            'customerRef' => 'customer-1',
            'billingAccountRef' => 'account-1',
            'subjectRef' => 'subject-1',
            'componentRef' => 'component-1',
            'agreementRef' => 'agreement-1',
            'agreementRevision' => 1,
            'attributionVersion' => 'host_snapshot/1',
            'effectiveAt' => '2026-12-01T00:00:00Z',
        ],
        'interval' => [
            'start' => '2026-12-01T00:00:00Z',
            'end' => '2027-01-01T00:00:00Z',
        ],
        'eventAt' => '2026-12-10T10:00:00Z',
        'receivedAt' => '2026-12-10T10:00:05Z',
        'value' => [
            'kind' => 'decimal',
            'quantity' => $quantity,
        ],
        'grouping' => [
            ['name' => 'model', 'value' => 'gpt'],
        ],
        'billableStream' => [
            'mode' => 'normalized_net_billable',
            'policy' => 'exclusive_commercial_basis/1',
            'commercialBasisRef' => 'commercial-units-1',
            'normalizationRef' => 'net-overage-1',
        ],
        'provenance' => [
            'collector' => 'host-adapter',
            'version' => '1',
            'sourceRef' => 'usage-1',
            'sourceDigest' => 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
        ],
    ];
}

function tokenPriceV1PerUnit2Payload(string $numerator = '1', string $denominator = '1000', int $quantityScale = 3): array
{
    return [
        'schema' => 'rating.price.v1',
        'sellerRef' => 'seller-1',
        'priceVersionRef' => 'price-token-1',
        'componentRef' => 'component-1',
        'effectiveInterval' => [
            'start' => '2026-12-01T00:00:00Z',
            'end' => '2027-01-01T00:00:00Z',
        ],
        'unit' => 'token',
        'quantityPrecision' => 'decimal_24_9/1',
        'amountPrecision' => 'decimal_24_12/1',
        'currency' => 'USD',
        'currencyExponentVersion' => 'ISO4217-2026-10',
        'strategy' => [
            'id' => 'per_unit',
            'version' => 2,
            'unitPrice' => '0.002',
            'quantityConversion' => [
                'numerator' => $numerator,
                'denominator' => $denominator,
                'quantityScale' => $quantityScale,
            ],
        ],
        'commitment' => [
            'policy' => 'same_component_period/1',
            'scope' => 'seller_customer_component_period',
            'minimumMinor' => '0',
            'maximumMinor' => '9223372036854775807',
        ],
        'modifierOrder' => 'strategy_bounds_tax_round/1',
        'rounding' => [
            'policy' => 'named_boundaries/1',
            'mode' => 'HALF_EVEN',
            'boundaries' => 'subtotal_then_bounds_then_tax',
        ],
        'taxSnapshot' => [
            'snapshotRef' => 'host-tax-1',
            'snapshotDigest' => 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
            'policyVersion' => 'host-approved-tax/1',
            'correctionPolicyVersion' => 'host-approved-correction/1',
            'mode' => 'exclusive',
            'rate' => '0.00',
        ],
        'chargeTiming' => 'arrears',
        'quantityBasis' => 'aggregate',
        'billableStream' => [
            'mode' => 'normalized_net_billable',
            'policy' => 'exclusive_commercial_basis/1',
            'commercialBasisRef' => 'commercial-units-1',
            'normalizationRef' => 'net-overage-1',
        ],
        'allowancePolicy' => 'none/1',
        'commercialPolicyRef' => 'approved-agreement-1',
    ];
}

it('exposes supportedUnits per revision and rejects unknown revisions', function (): void {
    expect(Codec::forRevision('2026-10')->supportedUnits())
        ->toBe(['call', 'seat', 'byte', 'member', 'second', 'event'])
        ->and(Codec::forRevision('2026-12')->supportedUnits())
        ->toBe(['call', 'seat', 'byte', 'member', 'second', 'event', 'token']);

    expect(fn (): Codec => Codec::forRevision('2099-01'))
        ->toThrow(ProblemException::class);

    try {
        Codec::forRevision('2099-01');
    } catch (ProblemException $exception) {
        expect($exception->problem)->toBe(ProblemCode::SchemaUnsupported);
    }
});

it('rejects token unit and per_unit@2 on 2026-10', function (): void {
    $codec202610 = Codec::forRevision('2026-10');

    try {
        $codec202610->decode(signEnvelope($codec202610, tokenFactV2Payload('1000')));
        throw new LogicException('2026-10 accepted token unit on usage.fact.v2');
    } catch (ProblemException $exception) {
        expect($exception->problem)->toBe(ProblemCode::ValidationInvalid);
    }

    try {
        $codec202610->decode(signEnvelope($codec202610, tokenPriceV1PerUnit2Payload()));
        throw new LogicException('2026-10 accepted per_unit@2 on rating.price.v1');
    } catch (ProblemException $exception) {
        expect($exception->problem)->toBe(ProblemCode::ValidationInvalid);
    }
});

it('accepts token unit and per_unit@2 on 2026-12', function (): void {
    $codec = Codec::forRevision('2026-12');

    $fact = $codec->decode(signEnvelope($codec, tokenFactV2Payload('1500.00')));
    expect($fact)->toBeInstanceOf(UsageFactV2::class);

    $price = $codec->decode(signEnvelope($codec, tokenPriceV1PerUnit2Payload('1', '1000', 3)));
    expect($price)->toBeInstanceOf(PriceVersion::class);
});

it('rejects fractional token quantities under BC-ENV-45 on 2026-12', function (): void {
    $codec = Codec::forRevision('2026-12');

    try {
        $codec->decode(signEnvelope($codec, tokenFactV2Payload('12.5')));
        throw new LogicException('accepted fractional token quantity');
    } catch (ProblemException $exception) {
        expect($exception->problem)->toBe(ProblemCode::ValidationInvalid)
            ->and($exception->getMessage())->toContain('BC-ENV-45');
    }

    $quantityPayload = [
        'schema' => 'usage.quantity.v1',
        'snapshotRef' => 'tokens-1',
        'sellerRef' => 'seller-1',
        'attribution' => [
            'customerRef' => 'customer-1',
            'billingAccountRef' => 'account-1',
            'subjectRef' => 'subject-1',
            'componentRef' => 'component-1',
            'agreementRef' => 'agreement-1',
            'agreementRevision' => 1,
            'attributionVersion' => 'host_snapshot/1',
            'effectiveAt' => '2026-12-01T00:00:00Z',
        ],
        'unit' => 'token',
        'quantity' => '0.25',
        'effectiveRevision' => 1,
        'effectiveInterval' => [
            'start' => '2026-12-01T00:00:00Z',
            'end' => '2027-01-01T00:00:00Z',
        ],
        'approvedBy' => 'host/ProductCoordinator',
        'approvalDigest' => 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
        'sourceDigest' => 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
        'prorationPolicy' => 'whole_interval/1',
    ];

    try {
        $codec->decode(signEnvelope($codec, $quantityPayload));
        throw new LogicException('accepted fractional token quantity on usage.quantity.v1');
    } catch (ProblemException $exception) {
        expect($exception->problem)->toBe(ProblemCode::ValidationInvalid)
            ->and($exception->getMessage())->toContain('BC-ENV-45');
    }
});

it('rejects inexact quantityConversion under BC-ENV-46 on 2026-12', function (): void {
    $codec = Codec::forRevision('2026-12');

    try {
        $codec->decode(signEnvelope($codec, tokenPriceV1PerUnit2Payload('1', '3', 6)));
        throw new LogicException('accepted inexact quantityConversion');
    } catch (ProblemException $exception) {
        expect($exception->problem)->toBe(ProblemCode::ValidationInvalid)
            ->and($exception->getMessage())->toContain('BC-ENV-46');
    }
});

it('preserves legacy usage.fact.v1 semantics on 2026-10', function (): void {
    $codec = Codec::forRevision('2026-10');
    $legacyFact = [
        'schema' => 'usage.fact.v1',
        'source' => 'tariffler',
        'factId' => 'legacy-token-1',
        'revision' => 1,
        'sellerRef' => 'seller-1',
        'subjectRef' => 'subject-1',
        'metricRef' => 'llm_tokens',
        'metricVersion' => 'v1',
        'semantics' => 'additive',
        'unit' => 'token',
        'quantity' => '100',
        'interval' => [
            'start' => '2026-10-01T00:00:00Z',
            'end' => '2026-10-02T00:00:00Z',
        ],
        'eventAt' => '2026-10-01T00:00:00Z',
        'observedAt' => '2026-10-01T00:00:01Z',
        'provenance' => [
            'collector' => 'usage-collector',
            'version' => '1.4.2',
            'sourceRef' => 'batch-77',
        ],
    ];

    $decoded = $codec->decode(signEnvelope($codec, $legacyFact));
    expect($decoded->schema)->toBe('usage.fact.v1');
});

it('decodes billium.capabilities.v2 into CapabilitiesV2', function (): void {
    $codec = Codec::forRevision('2026-12');
    $payload = [
        'schema' => 'billium.capabilities.v2',
        'installationRef' => 'inst-1',
        'environment' => 'prod',
        'specRevision' => SpecRevision::CURRENT,
        'specHash' => SpecRevision::HASHES[SpecRevision::CURRENT],
        'supportedSpecRevisions' => [
            ['revision' => '2026-10', 'specHash' => SpecRevision::HASHES['2026-10']],
            ['revision' => '2026-12', 'specHash' => SpecRevision::HASHES['2026-12']],
        ],
        'supportedUnits' => $codec->supportedUnits(),
        'usageIngressSchemas' => ['usage.fact.v1', 'usage.fact.v2'],
        'ratingStrategyVersions' => ['per_unit@1', 'per_unit@2'],
        'serverApiVersion' => '1',
        'acceptedCommandSchemas' => ['usage.fact.v2'],
        'producedResponseSchemas' => ['usage.receipt.v2'],
        'eventSchemas' => ['billium.event.v2'],
        'enabledModules' => [
            ['module' => 'usage', 'apiVersion' => '2'],
        ],
        'moduleApiVersions' => ['usage' => '2'],
        'features' => [
            'charge' => true,
            'refund' => true,
            'find' => true,
            'receipt' => true,
            'document' => true,
            'execution' => true,
        ],
        'effects' => [
            'sale' => true,
            'authorize' => true,
            'capture' => true,
            'voidAuthorization' => true,
            'cancel' => true,
        ],
        'limits' => [
            'maxBodyBytes' => 1048576,
            'maxDepth' => 32,
        ],
        'lookupCompletenessPolicyRef' => 'policy-1',
        'catalogs' => [
            [
                'eventContractRevision' => 'events-2026-10-v2',
                'envelopeSchema' => 'billium.event.v2',
                'eventTypes' => ['payment.effect.outcome'],
            ],
        ],
        'selectionMode' => 'explicit',
    ];

    $decoded = $codec->decode(signEnvelope($codec, $payload));
    expect($decoded)->toBeInstanceOf(CapabilitiesV2::class)
        ->and($decoded->supportedUnits)->toBe(['call', 'seat', 'byte', 'member', 'second', 'event', 'token'])
        ->and($decoded->ratingStrategyVersions)->toBe(['per_unit@1', 'per_unit@2']);
});
