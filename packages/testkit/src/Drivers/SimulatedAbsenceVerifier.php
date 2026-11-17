<?php

declare(strict_types=1);

namespace Billium\Testkit\Drivers;

use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\DriverCapability;
use Billium\Execution\Enums\QualificationMode;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Fixtures\FixtureValue;
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
        if ($binding->merchantAccountRef !== $this->capabilities->merchantAccountRef || $binding->capabilityDigest !== $this->capabilities->digest || $this->capabilities->details->lookupVisibility !== 'complete' || $from > $through || $through < $this->clock->now) {
            throw new RuntimeException('Incomplete simulated absence coverage');
        }
        $scope = hash('sha256', serialize($binding->scope));
        $opened = [];
        $retired = [];
        $records = $this->host->records();
        foreach ($records as $record) {
            if ($record instanceof DispatchTicket && FixtureValue::same($record->claims->scope, $binding->scope) && $record->issuedAt < $from) {
                throw new RuntimeException('Absence horizon excludes issued transport');
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
            }
            if ($record->kind === 'host.retire') {
                $retired[$session] = true;
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

        return [hash('sha256', serialize($records)), hash('sha256', serialize($retired))];
    }

    public function certify(FrozenBinding $binding, string $reference, DateTimeImmutable $from, DateTimeImmutable $through): AbsenceCertificate
    {
        [$manifest, $retirement] = $this->coverage($binding, $reference, $from, $through);
        $nonce = bin2hex(random_bytes(16));
        $certificate = new AbsenceCertificate('simulated-absence:'.$nonce, $binding, $reference, $this->capabilities->details->lookupNamespace, 'simulated-through:'.$through->format(DATE_ATOM), $from, $through, $manifest, $retirement, 'simulated-retired-scope:'.hash('sha256', serialize($binding->scope)), $this->capabilities->qualificationEvidenceRef, 'simulated-absence-authority.v1', hash('sha256', $nonce.$manifest.$retirement), $this->clock->now->modify('+30 seconds'));
        $this->certificates->append($certificate);

        return $certificate;
    }

    public function verify(AbsenceCertificate $certificate, FrozenBinding $binding): void
    {
        [$manifest, $retirement] = $this->coverage($binding, $certificate->providerReference, $certificate->coverageFrom, $certificate->coverageThrough);
        $issued = false;
        foreach ($this->certificates->records() as $record) {
            if ($record instanceof AbsenceCertificate && FixtureValue::same($record, $certificate)) {
                $issued = true;
            }
        }
        if (! $issued || ! FixtureValue::same($certificate->binding, $binding) || $certificate->expiresAt <= $this->clock->now || $certificate->closedDispatchManifestDigest !== $manifest || $certificate->retirementProofDigest !== $retirement) {
            throw new RuntimeException('Invalid or stale simulated absence certificate');
        }
    }
}
