<?php

declare(strict_types=1);

namespace Billium\Testkit\Drivers;

use Billium\Contracts\Contracts\Codec;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\DriverCapability;
use Billium\Execution\Enums\QualificationMode;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\JournalEvent;
use Closure;
use DateTimeImmutable;
use Packman\Attributes\Api;
use RuntimeException;

/** Authenticates only explicitly simulated complete scopes against independent journals. */
#[Api(since: '1.0')]
final readonly class SimulatedAbsenceVerifier implements ProviderAbsenceVerifier
{
    /** @param Closure():int $transactionLevel */
    public function __construct(private DurableJournal $provider, private DurableJournal $host, private DurableJournal $certificates, private FixtureClock $clock, private CapabilitySnapshot $capabilities, private Closure $transactionLevel)
    {
        if ($capabilities->qualificationMode !== QualificationMode::Simulated) {
            throw new RuntimeException('Test absence verifier accepts only simulated qualification');
        }
    }

    /** @return array{string,string} */
    private function coverage(FrozenBinding $binding, string $reference, DateTimeImmutable $from, DateTimeImmutable $through): array
    {
        if (($this->transactionLevel)() !== 0) {
            throw new RuntimeException('Absence host call inside transaction');
        }
        $this->capabilities->requireCapability(DriverCapability::AbsenceCertification, false);
        if ($binding->merchantAccountRef !== $this->capabilities->merchantAccountRef || $binding->capabilityDigest !== $this->capabilities->digest || $this->capabilities->details->lookupVisibility !== 'complete' || $from > $through || $through > $this->clock->now || $binding->driverId !== $this->capabilities->driverId || $binding->driverContractVersion !== $this->capabilities->driverContractVersion || $reference === '') {
            throw new RuntimeException('Incomplete simulated absence coverage');
        }
        $scope = hash('sha256', (new Codec)->canonicalize(get_object_vars($binding->scope)));
        $manifest = [];
        $opened = [];
        $retired = [];
        $records = $this->host->records();
        foreach ($records as $record) {
            if ($record instanceof DispatchTicket && get_object_vars($record->claims->scope) === get_object_vars($binding->scope) && $record->claims->providerReference === $reference) {
                if ($record->issuedAt < $from || $record->issuedAt > $through || $record->claims->bindingDigest !== DeterministicWriterHost::bindingDigest($binding)) {
                    throw new RuntimeException('Absence horizon or binding excludes issued transport');
                }
                $manifest[] = ['ticket' => $record->ticketRef, 'issued' => $record->issuedAt->format('U.u'), 'expires' => $record->expiresAt->format('U.u'),
                    'signature' => $record->signatureDigest, 'fence' => $record->egressFenceRef, 'reference' => $reference];
            }
            if (! $record instanceof JournalEvent || ($record->data['scope'] ?? null) !== $scope) {
                continue;
            }
            $session = $record->data['session'] ?? null;
            if (! is_string($session)) {
                throw new RuntimeException('Invalid simulated host record');
            }
            if ($record->kind === 'host.open') {
                $opened[$session] = true;
                unset($retired[$session]);
            }
            if ($record->kind === 'host.retire') {
                if (! isset($opened[$session]) || ! is_string($record->data['reason'] ?? null) || $record->data['reason'] === '') {
                    throw new RuntimeException('Retirement lacks original granted scope and proof');
                }
                $retired[$session] = $record->data['reason'];
            }
        }
        if ($opened === [] || array_diff_key($opened, $retired) !== []) {
            throw new RuntimeException('No complete simulated retirement fence');
        }
        foreach ($this->provider->records() as $record) {
            if ($record instanceof ProviderRecord && $record->request->binding->scope->environment === $binding->scope->environment && $record->request->binding->merchantAccountRef === $binding->merchantAccountRef && $record->request->providerReference === $reference) {
                throw new RuntimeException('Provider has retained reference; absence is false');
            }
        }

        return [hash('sha256', (new Codec)->canonicalize($manifest)), hash('sha256', (new Codec)->canonicalize($retired))];
    }

    public function certify(FrozenBinding $binding, string $reference, DateTimeImmutable $from, DateTimeImmutable $through): AbsenceCertificate
    {
        if ($through->format('U.u') !== $this->clock->now->format('U.u')) {
            throw new RuntimeException('Simulated provider can certify only through its current complete watermark');
        }
        [$manifest, $retirement] = $this->coverage($binding, $reference, $from, $through);
        $fields = [
            'certificateRef' => 'simulated-absence:'.bin2hex(random_bytes(16)), 'binding' => $binding, 'providerReference' => $reference,
            'providerNamespace' => $this->capabilities->details->lookupNamespace, 'providerCompletenessWatermark' => 'simulated-through:'.$through->format(DATE_ATOM),
            'coverageFrom' => $from, 'coverageThrough' => $through, 'closedDispatchManifestDigest' => $manifest, 'retirementProofDigest' => $retirement,
            'egressFenceRef' => 'simulated-retired-scope:'.hash('sha256', (new Codec)->canonicalize(get_object_vars($binding->scope))),
            'qualificationEvidenceRef' => $this->capabilities->qualificationEvidenceRef, 'issuerRef' => 'simulated-absence-authority.v1',
            'expiresAt' => $this->clock->now->modify('+30 seconds'),
        ];
        $certificate = new AbsenceCertificate(...[...$fields, 'signatureDigest' => self::signature($fields)]);
        $this->certificates->append($certificate);

        return $certificate;
    }

    /** @param array<mixed> $fields */
    private static function signature(array $fields): string
    {
        unset($fields['signatureDigest']);
        $body = ['schema' => 'billium.testkit.simulated-absence.v1'];
        foreach ($fields as $name => $value) {
            if (! is_string($name)) {
                throw new RuntimeException('Invalid simulated certificate field name');
            }
            $body[$name] = match (true) {
                $value instanceof FrozenBinding => DeterministicWriterHost::bindingDigest($value),
                $value instanceof DateTimeImmutable => $value->format('U.u'),
                is_string($value) => $value,
                default => throw new RuntimeException('Unknown simulated certificate field'),
            };
        }

        return hash_hmac('sha256', (new Codec)->canonicalize($body), 'simulated-absence-fixture-key-not-production');
    }

    public function verify(AbsenceCertificate $certificate, FrozenBinding $binding): void
    {
        [$manifest, $retirement] = $this->coverage($binding, $certificate->providerReference, $certificate->coverageFrom, $certificate->coverageThrough);
        $issued = false;
        foreach ($this->certificates->records() as $record) {
            if ($record instanceof AbsenceCertificate && $record->certificateRef === $certificate->certificateRef && hash_equals($record->signatureDigest, $certificate->signatureDigest)) {
                $issued = true;
            }
        }
        if (! $issued || ! hash_equals(self::signature(get_object_vars($certificate)), $certificate->signatureDigest)
            || DeterministicWriterHost::bindingDigest($certificate->binding) !== DeterministicWriterHost::bindingDigest($binding) || $certificate->providerNamespace !== $this->capabilities->details->lookupNamespace
            || $certificate->qualificationEvidenceRef !== $this->capabilities->qualificationEvidenceRef
            || $certificate->issuerRef !== 'simulated-absence-authority.v1' || $certificate->providerCompletenessWatermark !== 'simulated-through:'.$certificate->coverageThrough->format(DATE_ATOM) || $certificate->expiresAt <= $this->clock->now || $certificate->closedDispatchManifestDigest !== $manifest || $certificate->retirementProofDigest !== $retirement) {
            throw new RuntimeException('Invalid or stale simulated absence certificate');
        }
    }
}
