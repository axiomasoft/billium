<?php

declare(strict_types=1);

namespace Billium\Testkit\Fiscal;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\Fiscal\FiscalDriver;
use Billium\Execution\Data\Fiscal\FiscalCallbackEnvelope;
use Billium\Execution\Data\Fiscal\FiscalCallbackVerification;
use Billium\Execution\Data\Fiscal\FiscalDriverCapabilities;
use Billium\Execution\Data\Fiscal\FiscalDriverResult;
use Billium\Execution\Data\Fiscal\FiscalIntentRecord;
use Billium\Execution\Data\Fiscal\FiscalLookupRequest;
use Billium\Execution\Data\Fiscal\FiscalRegisterRequest;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalDriverOutcomeState;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Closure;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Api;
use RuntimeException;

/**
 * Deterministic simulated fiscal driver for testing psp_passthrough and fiscal_driver flows,
 * lost registration responses, 24h outages, duplicate registration guards, and callback trust verification.
 */
#[Api(since: '1.0')]
final class SimulatedFiscalDriver implements FiscalDriver
{
    public int $registerCalls = 0;

    public int $lookupCalls = 0;

    public int $duplicateRegistrations = 0;

    /** @var list<FiscalRegisterRequest> */
    public array $recordedRegisters = [];

    /** @var list<FiscalLookupRequest> */
    public array $recordedLookups = [];

    private bool $offline = false;

    /** @var array<string, FiscalDriverResult> externalId => stored remote receipt */
    private array $remoteReceipts = [];

    /** @var array<string, bool> externalId => true when register should lose response after remote registration */
    private array $loseResponseFor = [];

    /** @var array<string, FiscalDriverResult> externalId => scripted register result */
    private array $scriptedRegisters = [];

    private int $sequence = 0;

    /**
     * @param  list<FiscalRoute>  $supportedRoutes
     * @param  list<FiscalCallbackTrustStrategy>  $supportedTrustStrategies
     * @param  null|Closure():int  $txLevelProbe
     */
    public function __construct(
        private readonly string $id = 'simulated-fiscal',
        private readonly string $version = 'fiscal-driver.v1',
        private readonly array $supportedRoutes = [FiscalRoute::FiscalDriver, FiscalRoute::PspPassthrough],
        private readonly array $supportedTrustStrategies = [
            FiscalCallbackTrustStrategy::HmacSignature,
            FiscalCallbackTrustStrategy::DriverVerified,
            FiscalCallbackTrustStrategy::MutualTls,
        ],
        private readonly bool $supportsLookup = true,
        private readonly bool $supportsCorrection = true,
        private readonly string $callbackSecret = 'testkit-fiscal-hmac-secret',
        private readonly ?Closure $txLevelProbe = null,
    ) {}

    public function driverId(): string
    {
        return $this->id;
    }

    public function contractVersion(): string
    {
        return $this->version;
    }

    public function capabilities(): FiscalDriverCapabilities
    {
        return new FiscalDriverCapabilities(
            driverId: $this->id,
            contractVersion: $this->version,
            supportedRoutes: $this->supportedRoutes,
            supportedCallbackTrustStrategies: $this->supportedTrustStrategies,
            supportsLookup: $this->supportsLookup,
            supportsCorrection: $this->supportsCorrection,
        );
    }

    public function setOffline(bool $offline): void
    {
        $this->offline = $offline;
    }

    /**
     * Simulates remote receipt registration succeeding while the HTTP/network response is lost.
     * Any subsequent registerReceipt() with the same externalId increments $duplicateRegistrations.
     */
    public function loseRegisterResponseFor(string $externalId): void
    {
        $this->loseResponseFor[$externalId] = true;
    }

    public function scriptRegisterResult(string $externalId, FiscalDriverResult $result): void
    {
        $this->scriptedRegisters[$externalId] = $result;
    }

    public function registerReceipt(FiscalRegisterRequest $request): FiscalDriverResult
    {
        $this->assertOutsideTx();
        $this->registerCalls++;
        $this->recordedRegisters[] = $request;

        if ($this->offline) {
            throw new RuntimeException('Simulated fiscal provider transport outage during registerReceipt');
        }

        if (isset($this->remoteReceipts[$request->externalId])) {
            $this->duplicateRegistrations++;
            throw new LogicException('Duplicate fiscal registration detected for externalId '.$request->externalId);
        }

        if (isset($this->scriptedRegisters[$request->externalId])) {
            $scripted = $this->scriptedRegisters[$request->externalId];
            $this->remoteReceipts[$request->externalId] = $scripted;

            return $scripted;
        }

        $this->sequence++;
        $receipt = new FiscalDriverResult(
            state: FiscalDriverOutcomeState::Registered,
            providerReceiptId: 'rcpt-'.$this->id.'-'.$this->sequence,
            fiscalDocumentNumber: 'FDN-'.str_pad((string) $this->sequence, 6, '0', STR_PAD_LEFT),
            sanitizedDetails: [
                'driverId' => $this->id,
                'route' => $request->route->value,
                'merchantAccountRef' => $request->credentials->merchantAccountRef,
                'credentialVersionRef' => $request->credentials->credentialVersionRef,
            ],
            occurredAt: new DateTimeImmutable,
        );
        $this->remoteReceipts[$request->externalId] = $receipt;

        if (($this->loseResponseFor[$request->externalId] ?? false) === true) {
            unset($this->loseResponseFor[$request->externalId]);
            throw new RuntimeException('Simulated lost register response after remote fiscal acceptance');
        }

        return $receipt;
    }

    public function findReceipt(FiscalLookupRequest $request): FiscalDriverResult
    {
        $this->assertOutsideTx();
        $this->lookupCalls++;
        $this->recordedLookups[] = $request;

        if ($this->offline) {
            throw new RuntimeException('Simulated fiscal provider transport outage during findReceipt');
        }

        $stored = $this->remoteReceipts[$request->externalId] ?? null;
        if ($stored === null) {
            return new FiscalDriverResult(
                state: FiscalDriverOutcomeState::Unknown,
                errorCode: 'not_found_yet',
                sanitizedDetails: [
                    'lookupAccount' => $request->credentials->merchantAccountRef,
                    'credentialVersionRef' => $request->credentials->credentialVersionRef,
                ],
                occurredAt: new DateTimeImmutable,
            );
        }

        return new FiscalDriverResult(
            state: $stored->state,
            providerReceiptId: $stored->providerReceiptId,
            fiscalDocumentNumber: $stored->fiscalDocumentNumber,
            errorCode: $stored->errorCode,
            sanitizedDetails: [
                ...$stored->sanitizedDetails,
                'recoveredByLookup' => true,
                'lookupAccount' => $request->credentials->merchantAccountRef,
                'lookupCredentialVersionRef' => $request->credentials->credentialVersionRef,
            ],
            occurredAt: new DateTimeImmutable,
        );
    }

    public function verifyCallback(FiscalCallbackEnvelope $envelope, FiscalIntentRecord $intent): FiscalCallbackVerification
    {
        if ($envelope->driverId !== $this->id || $envelope->driverContractVersion !== $this->version) {
            return new FiscalCallbackVerification(false, null, 'driver_version_mismatch');
        }
        if (! in_array($envelope->trustStrategy, $this->supportedTrustStrategies, true)) {
            return new FiscalCallbackVerification(false, null, 'unsupported_trust_strategy');
        }

        $expected = $this->signCallback($envelope->externalId, $envelope->externalEventId, $envelope->trustStrategy, $envelope->payload);
        if (! hash_equals($expected, $envelope->signature)) {
            return new FiscalCallbackVerification(false, null, 'invalid_callback_signature');
        }

        $rawState = $envelope->payload['state'] ?? null;
        if (! is_string($rawState)) {
            return new FiscalCallbackVerification(false, null, 'missing_callback_state');
        }
        $state = FiscalDriverOutcomeState::tryFrom($rawState);
        if ($state === null) {
            return new FiscalCallbackVerification(false, null, 'invalid_callback_state');
        }

        $receiptId = is_string($envelope->payload['providerReceiptId'] ?? null) ? $envelope->payload['providerReceiptId'] : null;
        $docNumber = is_string($envelope->payload['fiscalDocumentNumber'] ?? null) ? $envelope->payload['fiscalDocumentNumber'] : null;
        $errorCode = is_string($envelope->payload['errorCode'] ?? null) ? $envelope->payload['errorCode'] : null;

        return new FiscalCallbackVerification(
            trusted: true,
            result: new FiscalDriverResult(
                state: $state,
                providerReceiptId: $receiptId,
                fiscalDocumentNumber: $docNumber,
                errorCode: $errorCode,
                sanitizedDetails: [
                    'verifiedBy' => $this->id,
                    'trustStrategy' => $envelope->trustStrategy->value,
                    'intentRef' => $intent->intentRef,
                ],
                occurredAt: $envelope->receivedAt,
            ),
        );
    }

    /** @param array<string, mixed> $payload */
    public function signCallback(
        string $externalId,
        string $externalEventId,
        FiscalCallbackTrustStrategy $strategy,
        array $payload,
    ): string {
        $canonical = (new Codec)->canonicalize([
            'driverId' => $this->id,
            'contractVersion' => $this->version,
            'externalId' => $externalId,
            'externalEventId' => $externalEventId,
            'trustStrategy' => $strategy->value,
            'payload' => $payload,
        ]);

        return 'sig:'.hash_hmac('sha256', $canonical, $this->callbackSecret);
    }

    private function assertOutsideTx(): void
    {
        if ($this->txLevelProbe !== null && ($this->txLevelProbe)() !== 0) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'SimulatedFiscalDriver must execute outside every database transaction');
        }
    }
}
