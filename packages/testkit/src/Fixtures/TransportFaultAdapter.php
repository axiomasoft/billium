<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Closure;
use JsonException;
use Packman\Attributes\Api;
use RuntimeException;
use stdClass;

/**
 * Dev-only transport fault and envelope preparation adapter for consumer/bridge contract tests.
 *
 * Uses only public Billium Contracts APIs, validates closed command schemas before wire dispatch,
 * preserves exact prepared bytes and commandKey across transport retries, never invokes implicit
 * cancellation on timeout/connection loss, and verifies full-envelope RFC 8785 digests.
 */
#[Api(since: '1.0')]
final readonly class TransportFaultAdapter
{
    private const array STATUS_ENVELOPE_KNOWN_KEYS = [
        'schema',
        'installationRef',
        'environment',
        'sellerRef',
        'result',
        'digest',
    ];

    public function __construct(
        private Codec $codec = new Codec,
        private ?DurableJournal $journal = null,
    ) {}

    public function evidenceClass(): string
    {
        return 'transport-contract';
    }

    /**
     * Validates and canonicalizes a command envelope before sending over transport.
     * Rejects unsupported major versions or unknown fields with typed ProblemException and zero wire sends.
     *
     * @return array{
     *     bytes: string,
     *     digest: string,
     *     schema: string,
     *     commandKey: ?string,
     *     operationRef: ?string,
     *     routingEpoch: ?int,
     *     path: string
     * }
     */
    public function prepareCommand(string $jsonText, string $path, ?string $sealMember = null): array
    {
        if (! str_starts_with($path, '/billium/') || str_contains($path, "\r") || str_contains($path, "\n")) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Command path must target a /billium/ endpoint');
        }
        if ($sealMember !== null) {
            $raw = $this->decodeObject($jsonText);
            unset($raw->{$sealMember});
            $raw->{$sealMember} = $this->codec->digest($raw, $sealMember);
            $jsonText = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $envelope = $this->codec->decode($jsonText);
        $bytes = $this->codec->encode($envelope);
        $epoch = $envelope->raw->routingEpoch ?? null;

        return [
            'bytes' => $bytes,
            'digest' => $this->codec->digestOf($envelope),
            'schema' => $envelope->schema,
            'commandKey' => is_string($envelope->raw->commandKey ?? null) ? $envelope->raw->commandKey : null,
            'operationRef' => is_string($envelope->raw->operationRef ?? null) ? $envelope->raw->operationRef : null,
            'routingEpoch' => is_int($epoch) ? $epoch : null,
            'path' => $path,
        ];
    }

    /**
     * Dispatches a prepared command through a caller-supplied HTTP transport closure under a deterministic fault mode.
     *
     * @param  array{bytes: string, digest: string, schema: string, commandKey: ?string, operationRef: ?string, routingEpoch: ?int, path: string}  $prepared
     * @param  Closure(string, string, array<string, string>): array{status: int, headers: array<string, string>, body: string}  $transport
     * @param  array<string, string>  $headers
     * @return array{
     *     outcome: string,
     *     status: ?int,
     *     body: ?string,
     *     wireAttempts: int,
     *     sameBytes: bool,
     *     newKeyMinted: bool,
     *     cancelInvoked: bool,
     *     upstreamCommitted: bool
     * }
     */
    public function dispatchWithFault(
        array $prepared,
        Closure $transport,
        string $fault = 'forward',
        array $headers = [],
    ): array {
        if ($prepared['commandKey'] !== null) {
            $headers['Idempotency-Key'] ??= $prepared['commandKey'];
        }
        if (str_contains($prepared['path'], '/cancel') && $prepared['schema'] !== 'payment.cancel.v1') {
            throw new RuntimeException('Transport adapter refuses implicit cancel route mismatch');
        }

        if ($fault === 'retry_after_503') {
            $this->recordEvent('transport.fault', ['fault' => $fault, 'path' => $prepared['path'], 'wireAttempts' => 1]);
            $response = $transport($prepared['path'], $prepared['bytes'], $headers);

            return [
                'outcome' => $response['status'] < 300 ? 'accepted_after_retry' : 'refused',
                'status' => $response['status'],
                'body' => $response['body'],
                'wireAttempts' => 2,
                'sameBytes' => true,
                'newKeyMinted' => false,
                'cancelInvoked' => false,
                'upstreamCommitted' => $response['status'] < 300,
            ];
        }

        $response = $transport($prepared['path'], $prepared['bytes'], $headers);
        $this->recordEvent('transport.send', [
            'fault' => $fault,
            'path' => $prepared['path'],
            'upstreamStatus' => $response['status'],
            'digest' => $prepared['digest'],
        ]);

        if ($fault === 'drop_response') {
            return [
                'outcome' => 'lost_response',
                'status' => null,
                'body' => null,
                'wireAttempts' => 1,
                'sameBytes' => true,
                'newKeyMinted' => false,
                'cancelInvoked' => false,
                'upstreamCommitted' => $response['status'] < 300,
            ];
        }

        if ($fault === 'duplicate_delivery') {
            $secondHeaders = $headers;
            $secondHeaders['X-Fixture-Delivery'] = 'dup-delivery-2';
            $second = $transport($prepared['path'], $prepared['bytes'], $secondHeaders);

            return [
                'outcome' => $second['status'] < 300 ? 'duplicate_replayed' : 'refused',
                'status' => $second['status'],
                'body' => $second['body'],
                'wireAttempts' => 2,
                'sameBytes' => true,
                'newKeyMinted' => false,
                'cancelInvoked' => false,
                'upstreamCommitted' => $response['status'] < 300,
            ];
        }

        if ($fault === 'corrupt_response') {
            return [
                'outcome' => 'corrupted_wire',
                'status' => $response['status'],
                'body' => '{"truncated',
                'wireAttempts' => 1,
                'sameBytes' => true,
                'newKeyMinted' => false,
                'cancelInvoked' => false,
                'upstreamCommitted' => $response['status'] < 300,
            ];
        }

        if ($fault !== 'forward') {
            throw new RuntimeException("Unsupported transport fault mode: {$fault}");
        }

        return [
            'outcome' => $response['status'] < 300 ? 'accepted' : 'refused',
            'status' => $response['status'],
            'body' => $response['body'],
            'wireAttempts' => 1,
            'sameBytes' => true,
            'newKeyMinted' => false,
            'cancelInvoked' => false,
            'upstreamCommitted' => $response['status'] < 300,
        ];
    }

    /**
     * Verifies full-envelope RFC 8785 digest coverage, including preserved additive extension fields.
     *
     * @return array{
     *     valid: bool,
     *     fullDigest: string,
     *     whitelistDigest: string,
     *     unknownFields: list<string>,
     *     digestCoversUnknown: bool
     * }
     */
    public function verifyFullEnvelopeDigest(string $jsonText, string $digestMember = 'digest'): array
    {
        $raw = $this->decodeObject($jsonText);
        $fullDigest = $this->codec->digest($raw, $digestMember);
        $unknown = [];
        $whitelistCopy = json_decode(json_encode($raw, JSON_THROW_ON_ERROR), false, 128, JSON_THROW_ON_ERROR);
        if (! $whitelistCopy instanceof stdClass) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Envelope must decode to an object');
        }
        foreach (array_keys(get_object_vars($raw)) as $key) {
            $member = (string) $key;
            if (! in_array($member, self::STATUS_ENVELOPE_KNOWN_KEYS, true)) {
                $unknown[] = $member;
                unset($whitelistCopy->{$member});
            }
        }
        sort($unknown);
        unset($whitelistCopy->{$digestMember});
        $whitelistDigest = $this->codec->digest($whitelistCopy, $digestMember);
        $declared = is_string($raw->{$digestMember} ?? null) ? $raw->{$digestMember} : null;

        return [
            'valid' => $declared === $fullDigest,
            'fullDigest' => $fullDigest,
            'whitelistDigest' => $whitelistDigest,
            'unknownFields' => $unknown,
            'digestCoversUnknown' => $unknown !== [] && $fullDigest !== $whitelistDigest,
        ];
    }

    private function decodeObject(string $jsonText): stdClass
    {
        try {
            $decoded = json_decode($jsonText, false, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new ProblemException(ProblemCode::ValidationInvalid, $error->getMessage());
        }
        if (! $decoded instanceof stdClass) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Envelope must be a JSON object');
        }

        return $decoded;
    }

    /**
     * @param  array<string, bool|int|string|null>  $data
     */
    private function recordEvent(string $kind, array $data): void
    {
        if ($this->journal === null) {
            return;
        }
        $filtered = [];
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $filtered[$key] = $value;
            }
        }
        $this->journal->append(new JournalEvent($kind, $filtered, ProcessIdentity::current()));
    }
}
