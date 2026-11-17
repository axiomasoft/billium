<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Custody;

use Billium\Execution\Contracts\CallbackPayloadArchive;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;
use Throwable;

/** Immutable custody for a delivery that cannot be stored as the qualified driver's ordinary sanitized envelope. */
#[Internal]
final readonly class CallbackFrame
{
    private const string SCHEMA = 'billium.execution.callback-frame.v1';

    public function __construct(private TransportBoundary $boundary, private ?CallbackPayloadArchive $archive = null) {}

    public function retain(MerchantRevision $route, ProviderEvidence $source, bool $authenticated): ProviderEvidence
    {
        $this->boundary->assertOutsideTransaction();
        $frame = ExecutionCodec::encode($source);
        $safe = strlen($frame) <= 24000 && preg_match('/"(?:pan|cvc|cvv|password|secret|accessToken|refreshToken|providerToken|authorization|apiKey|clientSecret)"\s*:/i', $frame) !== 1;
        if ($safe && $authenticated && $source->providerEventId !== null && $source->securePayloadRef !== null && ($source->sanitizedPayload['schema'] ?? null) !== self::SCHEMA) {
            return $source;
        }
        $ref = 'callback:'.bin2hex(random_bytes(20));
        $body = ['schema' => self::SCHEMA, 'frameDigest' => hash('sha256', $frame), 'route' => $route->revisionRef];
        $archive = null;
        if ($safe) {
            $body['original'] = ExecutionCodec::map($frame);
        } elseif ($this->archive !== null) {
            try {
                $archive = $this->archive->retain($route->scope, $route->revisionRef, $source);
                if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9._:-]{0,199}$/D', $archive) !== 1) {
                    throw new LogicException('Invalid trusted callback archive receipt');
                }
                $body['archived'] = true;
            } catch (Throwable) {
                $archive = null;
                $body['archiveUnavailable'] = true;
            }
        } else {
            $body['archiveUnavailable'] = true;
        }
        $event = $source->providerEventId;

        return new ProviderEvidence($ref, $route->scope, $route->providerId, $route->merchantAccountRef, $event !== null && strlen($event) <= 200 ? $event : null,
            $body['frameDigest'], $authenticated && ! isset($body['archiveUnavailable']) ? 'verified_frame' : 'unverified',
            $route->driverContractVersion, new DateTimeImmutable, $body, $archive ?? 'execution-custody:'.$ref);
    }

    public function original(ProviderEvidence $custody): ProviderEvidence
    {
        $body = $custody->sanitizedPayload;
        if (($body['schema'] ?? null) !== self::SCHEMA || preg_match('/^callback:[0-9a-f]{40}$/D', $custody->evidenceRef) !== 1) {
            return $custody;
        }
        $this->boundary->assertOutsideTransaction();
        $revisionRef = $body['route'] ?? null;
        if (! is_string($revisionRef) || $revisionRef === '' || strlen($revisionRef) > 200) {
            throw new LogicException('Archived callback route is unavailable');
        }
        if (is_array($body['original'] ?? null)) {
            $source = ExecutionCodec::decode(ProviderEvidence::class, ExecutionCodec::encode($body['original']));
        } elseif (($body['archived'] ?? null) === true && $custody->securePayloadRef !== null && preg_match('/^[A-Za-z0-9_][A-Za-z0-9._:-]{0,199}$/D', $custody->securePayloadRef) === 1 && $this->archive !== null) {
            $source = $this->archive->read($custody->scope, $revisionRef, $custody->securePayloadRef);
        } else {
            throw new LogicException('Original callback frame is unavailable for verification');
        }
        if (! hash_equals($custody->payloadDigest, hash('sha256', ExecutionCodec::encode($source)))) {
            throw new LogicException('Callback frame changed since custody');
        }

        return $source;
    }
}
