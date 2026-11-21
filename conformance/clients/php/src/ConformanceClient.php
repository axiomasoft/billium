<?php

declare(strict_types=1);

namespace Billium\ClientConformance;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use stdClass;

/**
 * Test-only transport client. It encodes a command once and never writes money (D9, D58, D61).
 *
 * Default is one send. A later send exists only for an explicit bound: one 401 credential
 * refresh, or a registry entry that is retryable and sameKey. The body, key and requestedAt
 * stay on the original {@see PreparedCommand}. A deadline or caller cancellation returns an
 * unresolved handle and does not send payment.cancel.v1.
 */
final class ConformanceClient
{
    private const array STATUS_KEYS = ['schema', 'installationRef', 'environment', 'sellerRef', 'result', 'digest'];

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    public function execute(array $case): array
    {
        $supported = $case['supportedSchemas'] ?? null;
        $codec = new Codec(is_array($supported) ? array_values(array_filter($supported, is_string(...))) : null);
        $op = is_string($case['op'] ?? null) ? $case['op'] : '';
        $id = is_string($case['id'] ?? null) ? $case['id'] : $op;

        return match ($op) {
            'prepare', 'send' => $this->transport($codec, $case, $id, $op === 'prepare'),
            'project-status' => $this->project($codec, $case, $id),
            'feed-page' => $this->feed($codec, $case, $id),
            'capabilities-refresh' => $this->capabilities($codec, $case, $id),
            'prepare-pair' => $this->pair($codec, $case, $id),
            default => $this->failure($id, ProblemCode::ValidationInvalid, 'unknown client op'),
        };
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function transport(Codec $codec, array $case, string $id, bool $prepareOnly): array
    {
        try {
            $prepared = $this->prepare($codec, $this->text($case), $this->seal($case), $this->path($case));
        } catch (ProblemException $error) {
            return $this->failure($id, $error->problem, $error->detail);
        }

        if ($prepareOnly) {
            return $this->finalize($this->base($id, 'prepared'), $prepared, []);
        }

        $script = is_array($case['transport'] ?? null) ? array_values($case['transport']) : [];
        $maxAttempts = is_int($case['maxAttempts'] ?? null) ? $case['maxAttempts'] : 1;
        $deadlineMs = is_int($case['deadlineMs'] ?? null) ? $case['deadlineMs'] : 15_000;
        $tokens = is_array($case['tokens'] ?? null) ? array_values(array_filter($case['tokens'], is_string(...))) : ['owner', 'refreshed'];
        $tokenIndex = 0;
        $authRefreshed = false;
        $waited = 0;
        $attempts = [];

        while (count($attempts) < $maxAttempts) {
            $authorization = 'Bearer '.($tokens[$tokenIndex] ?? $tokens[0]);
            $response = is_array($script[0] ?? null) ? array_shift($script) : ['transport' => 'closed'];
            $attempts[] = $this->attempt($prepared, $authorization);
            $transport = is_string($response['transport'] ?? null) ? $response['transport'] : null;
            if ($transport === 'deadline' || $transport === 'cancelled-after-send' || $transport === 'closed') {
                return $this->unresolved($id, $prepared, $attempts, $waited);
            }

            $status = is_int($response['status'] ?? null) ? $response['status'] : 0;
            if ($status === 401 && ! $authRefreshed && count($attempts) < $maxAttempts) {
                $authRefreshed = true;
                if ($tokenIndex + 1 < count($tokens)) {
                    $tokenIndex++;
                }
                continue;
            }

            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            $body = is_string($response['body'] ?? null) ? $response['body'] : '';
            if ($status >= 400) {
                $problem = $this->problem($codec, $body);
                $retryAfter = $this->retryAfterMs($headers);
                $fits = $problem['retryable'] && $problem['sameKey'] && $waited + $retryAfter <= $deadlineMs;
                if ($fits && count($attempts) < $maxAttempts) {
                    $waited += $retryAfter;
                    continue;
                }

                return $this->finalize($this->base($id, 'refused', [
                    'error' => ['code' => $problem['code'], 'receipt' => $problem['receipt']],
                    'waitedMs' => $waited,
                ]), $prepared, $attempts);
            }

            if ($status >= 200 && $status < 300) {
                $decoded = json_decode($body);
                if (json_last_error() !== JSON_ERROR_NONE || ! $decoded instanceof stdClass) {
                    return $this->finalize($this->base($id, 'refused', [
                        'error' => ['code' => ProblemCode::ValidationInvalid->value, 'receipt' => 'none'],
                        'waitedMs' => $waited,
                    ]), $prepared, $attempts);
                }

                return $this->finalize($this->base($id, 'accepted', ['waitedMs' => $waited]), $prepared, $attempts);
            }

            return $this->finalize($this->base($id, 'refused', [
                'error' => ['code' => ProblemCode::ValidationInvalid->value, 'receipt' => 'none'],
                'waitedMs' => $waited,
            ]), $prepared, $attempts);
        }

        return $this->unresolved($id, $prepared, $attempts, $waited);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function project(Codec $codec, array $case, string $id): array
    {
        $text = $this->text($case);
        try {
            $raw = $this->decodeObject($text);
        } catch (ProblemException $error) {
            return $this->failure($id, $error->problem, $error->detail);
        }
        $full = $codec->digest($raw, 'digest');
        $unknown = $this->unknownKeys($raw);
        $whitelist = $this->whitelistDigest($codec, $raw);
        $covers = $unknown !== [] && $full !== $whitelist;
        $meta = [
            'fullDigest' => $full,
            'whitelistDigest' => $whitelist,
            'preservedUnknown' => $unknown,
            'digestCoversUnknown' => $covers,
        ];
        if (isset($raw->digest) && $raw->digest !== $full) {
            return $this->base($id, 'refused', $meta + [
                'error' => ['code' => ProblemCode::ValidationInvalid->value, 'receipt' => 'none'],
            ]);
        }
        try {
            $codec->decode($text);
        } catch (ProblemException $error) {
            return $this->base($id, 'refused', $meta + [
                'error' => ['code' => $error->problem->value, 'receipt' => $error->problem->receipt()],
            ]);
        }

        return $this->base($id, 'accepted', $meta + ['totals' => $this->totals($raw)]);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function feed(Codec $codec, array $case, string $id): array
    {
        $text = $this->text($case);
        try {
            $envelope = $codec->decode($text);
        } catch (ProblemException $error) {
            return $this->failure($id, $error->problem, $error->detail);
        }
        $events = $envelope->raw->events ?? [];
        $schemas = [];
        if (is_array($events)) {
            foreach ($events as $event) {
                if ($event instanceof stdClass && is_string($event->schema ?? null)) {
                    $schemas[] = $event->schema;
                }
            }
        }

        return $this->base($id, 'accepted', [
            'cursor' => is_string($envelope->raw->nextCursor ?? null) ? $envelope->raw->nextCursor : null,
            'persisted' => true,
            'eventCount' => is_array($events) ? count($events) : 0,
            'preservedUnknown' => $schemas,
        ]);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function capabilities(Codec $codec, array $case, string $id): array
    {
        try {
            $prepared = $this->prepare($codec, $this->text($case), null, $this->path($case));
        } catch (ProblemException $error) {
            return $this->failure($id, $error->problem, $error->detail);
        }
        $replacement = is_array($case['replacement'] ?? null) ? $case['replacement'] : [];
        $accepted = is_array($replacement['acceptedCommandSchemas'] ?? null)
            ? array_values(array_filter($replacement['acceptedCommandSchemas'], is_string(...)))
            : [];
        if (! in_array($prepared->schema, $accepted, true)) {
            return $this->finalize($this->base($id, 'recovery', [
                'error' => ['code' => ProblemCode::SchemaUnsupported->value, 'receipt' => ProblemCode::SchemaUnsupported->receipt()],
            ]), $prepared, []);
        }

        return $this->finalize($this->base($id, 'retained'), $prepared, []);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function pair(Codec $codec, array $case, string $id): array
    {
        $leftText = is_string($case['leftText'] ?? null) ? $case['leftText'] : '';
        $rightText = is_string($case['rightText'] ?? null) ? $case['rightText'] : '';
        try {
            $left = $this->prepare($codec, $leftText, $this->seal($case), $this->path($case));
            $right = $this->prepare($codec, $rightText, $this->seal($case), $this->path($case));
        } catch (ProblemException $error) {
            return $this->failure($id, $error->problem, $error->detail);
        }
        $leftValue = $this->decodeObject($left->bytes);
        $rightValue = $this->decodeObject($right->bytes);

        return $this->finalize($this->base($id, 'prepared', [
            'isolated' => $left->bytes !== $right->bytes,
            'authorities' => [
                is_string($leftValue->sourceAuthority ?? null) ? $leftValue->sourceAuthority : null,
                is_string($rightValue->sourceAuthority ?? null) ? $rightValue->sourceAuthority : null,
            ],
            'preservedUnknown' => [
                is_string($leftValue->eventId ?? null) ? $leftValue->eventId : '',
                is_string($rightValue->eventId ?? null) ? $rightValue->eventId : '',
            ],
        ]), $left, []);
    }

    private function prepare(Codec $codec, string $text, ?string $seal, string $path): PreparedCommand
    {
        if ($seal !== null) {
            $raw = $this->decodeObject($text);
            unset($raw->{$seal});
            $raw->{$seal} = $codec->digest($raw, $seal);
            $text = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $envelope = $codec->decode($text);
        $bytes = $codec->encode($envelope);
        $epoch = $envelope->raw->routingEpoch ?? null;

        return new PreparedCommand(
            bytes: $bytes,
            digest: $codec->digestOf($envelope),
            commandKey: is_string($envelope->raw->commandKey ?? null) ? $envelope->raw->commandKey : null,
            operationRef: is_string($envelope->raw->operationRef ?? null) ? $envelope->raw->operationRef : null,
            schema: $envelope->schema,
            routingEpoch: is_int($epoch) ? $epoch : null,
            path: $path,
        );
    }

    /** @param array<string, mixed> $headers */
    private function retryAfterMs(array $headers): int
    {
        $value = $headers['retry-after'] ?? $headers['Retry-After'] ?? '0';
        if (is_int($value)) {
            return $value * 1000;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            return (int) $value * 1000;
        }

        return 0;
    }

    /**
     * @return array{code: string, receipt: string, retryable: bool, sameKey: bool}
     */
    private function problem(Codec $codec, string $body): array
    {
        try {
            $problem = $codec->decodeProblem($body);

            return [
                'code' => $problem->code->value,
                'receipt' => $problem->code->receipt(),
                'retryable' => $problem->code->retryable(),
                'sameKey' => $problem->code->sameKey(),
            ];
        } catch (ProblemException) {
            return [
                'code' => ProblemCode::ValidationInvalid->value,
                'receipt' => 'none',
                'retryable' => false,
                'sameKey' => false,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function totals(stdClass $raw): array
    {
        $result = $raw->result instanceof stdClass ? $raw->result : new stdClass();
        $applied = $result->applied instanceof stdClass ? $result->applied : null;
        $observed = $result->observed instanceof stdClass ? $result->observed : null;
        $exposure = $result->exposure instanceof stdClass ? $result->exposure : null;
        $hold = $result->financialHold instanceof stdClass ? $result->financialHold : null;
        $reconciliation = $result->reconciliation instanceof stdClass ? $result->reconciliation : null;

        return [
            'applied' => $applied instanceof stdClass ? [
                'capturedTotal' => $this->amount($applied, 'capturedTotal'),
                'refundedTotal' => $this->amount($applied, 'refundedTotal'),
            ] : null,
            'observed' => $observed instanceof stdClass ? [
                'capturedTotal' => $this->amount($observed, 'capturedTotal'),
                'refundedTotal' => $this->amount($observed, 'refundedTotal'),
            ] : null,
            'exposure' => $exposure instanceof stdClass ? [
                'currency' => is_string($exposure->currency ?? null) ? $exposure->currency : null,
                'overCaptured' => $this->amount($exposure, 'overCaptured'),
                'overRefunded' => $this->amount($exposure, 'overRefunded'),
            ] : null,
            'financialHold' => ['active' => $hold instanceof stdClass && ($hold->active ?? null) === true],
            'spendable' => $this->amount($result, 'spendable'),
            'projectionStatus' => is_string($result->projectionStatus ?? null) ? $result->projectionStatus : null,
            'pendingAllocationReason' => is_string($result->pendingAllocationReason ?? null) ? $result->pendingAllocationReason : null,
            'reconciliation' => ['status' => $reconciliation instanceof stdClass && is_string($reconciliation->status ?? null) ? $reconciliation->status : null],
        ];
    }

    private function amount(stdClass $node, string $key): ?string
    {
        $money = $node->{$key} ?? null;
        if (! $money instanceof stdClass || ! is_string($money->amount ?? null)) {
            return null;
        }

        return $money->amount;
    }

    /** @return list<string> */
    private function unknownKeys(stdClass $raw): array
    {
        $keys = [];
        foreach (array_keys(get_object_vars($raw)) as $key) {
            if (! in_array((string) $key, self::STATUS_KEYS, true)) {
                $keys[] = (string) $key;
            }
        }
        sort($keys);

        return $keys;
    }

    private function whitelistDigest(Codec $codec, stdClass $raw): string
    {
        $copy = json_decode(json_encode($raw, JSON_THROW_ON_ERROR), false, 128, JSON_THROW_ON_ERROR);
        if (! $copy instanceof stdClass) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'status envelope must be an object');
        }
        foreach (array_keys(get_object_vars($copy)) as $key) {
            if (! in_array((string) $key, self::STATUS_KEYS, true)) {
                unset($copy->{$key});
            }
        }
        unset($copy->digest);

        return $codec->digest($copy, 'digest');
    }

    private function decodeObject(string $text): stdClass
    {
        try {
            $raw = json_decode($text, false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ProblemException(ProblemCode::ValidationInvalid, $error->getMessage());
        }
        if (! $raw instanceof stdClass) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'envelope must be an object');
        }

        return $raw;
    }

    /** @param array<string, mixed> $case */
    private function text(array $case): string
    {
        if (is_string($case['jsonText'] ?? null)) {
            return $case['jsonText'];
        }

        throw new ProblemException(ProblemCode::ValidationInvalid, 'jsonText is required');
    }

    /** @param array<string, mixed> $case */
    private function seal(array $case): ?string
    {
        return is_string($case['seal'] ?? null) ? $case['seal'] : null;
    }

    /** @param array<string, mixed> $case */
    private function path(array $case): string
    {
        return is_string($case['path'] ?? null) ? $case['path'] : '/billium/v1/sellers/seller-a/payments/execute';
    }

    /** @return array{path: string, body: string, authorization: string, commandKey: ?string} */
    private function attempt(PreparedCommand $prepared, string $authorization): array
    {
        $parsed = json_decode($prepared->bytes, true);
        $key = is_array($parsed) && is_string($parsed['commandKey'] ?? null) ? $parsed['commandKey'] : null;

        return [
            'path' => $prepared->path,
            'body' => $prepared->bytes,
            'authorization' => $authorization,
            'commandKey' => $key,
        ];
    }

    /**
     * @param  list<array{path: string, body: string, authorization: string, commandKey: ?string}>  $attempts
     * @return array<string, mixed>
     */
    private function unresolved(string $id, PreparedCommand $prepared, array $attempts, int $waited): array
    {
        return $this->finalize($this->base($id, 'unresolved', [
            'error' => ['code' => ProblemCode::TransportUnavailable->value, 'receipt' => ProblemCode::TransportUnavailable->receipt()],
            'waitedMs' => $waited,
            'recovery' => [
                'commandKey' => $prepared->commandKey,
                'operationRef' => $prepared->operationRef,
                'digest' => $prepared->digest,
                'selectors' => ['commandKey', 'operationRef'],
            ],
        ]), $prepared, $attempts);
    }

    /** @return array<string, mixed> */
    private function failure(string $id, ProblemCode $code, string $detail): array
    {
        unset($detail);

        return $this->base($id, 'refused', [
            'error' => ['code' => $code->value, 'receipt' => $code->receipt()],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function base(string $id, string $outcome, array $extra = []): array
    {
        return array_replace([
            'id' => $id,
            'outcome' => $outcome,
            'error' => null,
            'cancelInvoked' => false,
            'newKey' => false,
            'rebuilt' => false,
            'sameBytes' => true,
            'attempts' => [],
            'authorizations' => [],
            'waitedMs' => 0,
            'prepared' => null,
            'totals' => null,
            'cursor' => null,
            'persisted' => false,
            'eventCount' => null,
            'preservedUnknown' => [],
            'fullDigest' => null,
            'whitelistDigest' => null,
            'digestCoversUnknown' => false,
            'routingEpoch' => null,
            'recovery' => null,
            'isolated' => null,
            'authorities' => [],
            'evidence_class' => 'transport-contract',
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<array{path: string, body: string, authorization: string, commandKey: ?string}>  $attempts
     * @return array<string, mixed>
     */
    private function finalize(array $result, PreparedCommand $prepared, array $attempts): array
    {
        $result['prepared'] = $prepared->export();
        $result['attempts'] = $attempts;
        $result['authorizations'] = array_map(static fn (array $attempt): string => $attempt['authorization'], $attempts);
        $result['routingEpoch'] = $prepared->routingEpoch;
        $same = true;
        $newKey = false;
        $cancel = false;
        foreach ($attempts as $attempt) {
            if ($attempt['body'] !== $prepared->bytes || $attempt['path'] !== $prepared->path) {
                $same = false;
            }
            if (str_contains($attempt['path'], '/cancel') || str_contains($attempt['body'], 'payment.cancel.v1')) {
                $cancel = true;
            }
            if ($prepared->commandKey !== null && $attempt['commandKey'] !== $prepared->commandKey) {
                $newKey = true;
            }
        }
        $result['sameBytes'] = $same;
        $result['rebuilt'] = $attempts !== [] && ! $same;
        $result['newKey'] = $newKey;
        $result['cancelInvoked'] = $cancel;

        return $result;
    }
}
