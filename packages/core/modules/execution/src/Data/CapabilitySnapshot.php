<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\CapabilitySnapshot as WireSnapshot;
use Billium\Execution\Enums\CapabilityRequirement;
use InvalidArgumentException;

#[Api]
final readonly class CapabilitySnapshot
{
    private WireSnapshot $values;

    public string $digest;

    public function __construct(string $json)
    {
        $codec = new Codec;
        $wire = $codec->decode($json);
        if (! $wire instanceof WireSnapshot) {
            throw new InvalidArgumentException('Expected payment.capabilities.v1');
        }
        $this->values = $wire;
        $this->digest = $codec->digestOf($wire);
    }

    /** @return array{driver: string, driverVersion: string, provider: string, providerApiVersion: string} */
    public function identity(): array
    {
        return ['driver' => $this->values->driver, 'driverVersion' => $this->values->driverVersion,
            'provider' => $this->values->provider, 'providerApiVersion' => $this->values->providerApiVersion];
    }

    public function supports(CapabilityRequirement $requirement): bool
    {
        $v = $this->values;
        $sale = $v->initiate === 'supported';
        $authorize = $v->authorize === 'supported';
        $refund = $v->refund === 'supported';
        $capture = $authorize && in_array($v->capture, ['full', 'partial_single', 'partial_multiple'], true);

        return match ($requirement) {
            CapabilityRequirement::Sale => $sale,
            CapabilityRequirement::Authorize => $authorize,
            CapabilityRequirement::IdempotentCreate => $sale && self::text($v->idempotency, 'mode') === 'keyed'
                && self::text($v->idempotency, 'keyScope') !== 'none' && self::integer($v->idempotency, 'guaranteedTtlSeconds') > 0,
            CapabilityRequirement::FindProvider => in_array($this->lookupMode('provider_id'), ['partial', 'complete'], true),
            CapabilityRequirement::FindBusiness => in_array($this->lookupMode('business_ref'), ['partial', 'complete'], true),
            CapabilityRequirement::Capture => $capture,
            CapabilityRequirement::PartialCapture => $capture && in_array($v->capture, ['partial_single', 'partial_multiple'], true),
            CapabilityRequirement::MultipleCapture => $capture && $v->capture === 'partial_multiple',
            CapabilityRequirement::VoidAuthorization => $authorize && in_array($v->voidMode, ['full', 'partial'], true),
            CapabilityRequirement::Refund => $refund,
            CapabilityRequirement::PartialRefund => $refund && $v->partialRefund === 'supported',
            CapabilityRequirement::MultipleRefund => $refund && $v->multipleRefund === 'supported',
            CapabilityRequirement::FindRefund => in_array($this->lookupMode('refund_ref'), ['partial', 'complete'], true),
            CapabilityRequirement::VerifyCallback => self::text($v->callback, 'authenticity') === 'signed' && self::text($v->callback, 'identity') === 'stable_event_id',
            CapabilityRequirement::FindEvent => in_array($this->lookupMode('event_id'), ['partial', 'complete'], true),
            CapabilityRequirement::Inventory => $v->inventory === 'supported',
            CapabilityRequirement::Settlement => $v->settlement === 'supported',
            CapabilityRequirement::Disputes => $v->disputes === 'supported',
            CapabilityRequirement::SavedMethod => $v->savedMethod === 'supported',
            CapabilityRequirement::RecurringConsent => $v->savedMethod === 'supported' && $v->recurringConsent === 'supported',
            CapabilityRequirement::FiscalPassthrough => $v->fiscalPassthrough === 'supported',
        };
    }

    public function allowsCertifiedAbsence(DriverLookup $query): bool
    {
        $binding = $query->binding;

        return $binding->capabilityDigest === $this->digest
            && $binding->driver === $this->values->driver
            && $binding->driverVersion === $this->values->driverVersion
            && $binding->providerApiVersion === $this->values->providerApiVersion
            && $this->lookupMode($query->selectorKind) === 'complete'
            && self::integer($this->lookup($query->selectorKind), 'retentionSeconds') > 0;
    }

    private function lookupMode(string $kind): string
    {
        return self::text($this->lookup($kind), 'mode');
    }

    private function lookup(string $kind): \stdClass
    {
        $value = $this->values->lookup->{$kind} ?? null;
        if (! $value instanceof \stdClass) {
            throw new InvalidArgumentException('Invalid lookup selector');
        }

        return $value;
    }

    private static function text(\stdClass $object, string $key): string
    {
        $value = $object->{$key} ?? null;

        return is_string($value) ? $value : throw new InvalidArgumentException('Expected string capability member');
    }

    private static function integer(\stdClass $object, string $key): int
    {
        $value = $object->{$key} ?? null;

        return is_int($value) ? $value : throw new InvalidArgumentException('Expected integer capability member');
    }
}
