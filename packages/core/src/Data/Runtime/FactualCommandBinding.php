<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;
use stdClass;

/** Canonical comparison for the host's detached attestation. Verification belongs to the host. */
#[Api(since: '1.0')]
final class FactualCommandBinding
{
    /** @param array<string,mixed> $message */
    public static function digest(string $destinationRef, array $message, FinancialScope $scope): string
    {
        $codec = new Codec;
        $body = $message['payload'] ?? null;
        if ((! $body instanceof stdClass && (! is_array($body) || array_is_list($body))) || $destinationRef !== 'ledger.inbox.payment_observation' || count($message) !== 6
            || ($message['schema'] ?? null) !== 'billium.command.v1' || ($message['commandType'] ?? null) !== PaymentObservation::SCHEMA
            || ! is_string($message['commandRef'] ?? null) || $message['commandRef'] === '' || strlen($message['commandRef']) > 200
            || ! is_string($message['businessRef'] ?? null) || $message['businessRef'] === '' || strlen($message['businessRef']) > 200
            || $scope->intentOwnerNamespace === null || ! in_array($scope->sourceAuthority, ['standalone-billium', 'remote-billium'], true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Incomplete qualified observation command identity');
        }
        $json = $codec->canonicalize($body);
        if (strlen($json) > 65536 || ($message['payloadDigest'] ?? null) !== 'sha256:'.hash('sha256', $json)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Qualified observation payload differs from its digest');
        }
        $o = $codec->decode($json);
        if (! $o instanceof PaymentObservation || $o->sourceAuthority !== $scope->sourceAuthority
            || $o->installationRef !== $scope->installationRef || $o->sellerRef !== $scope->sellerRef
            || $o->environment !== $scope->environment || $message['businessRef'] !== ($o->originalPaymentRef ?? $o->operationRef)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Qualified command and authenticated scope disagree');
        }

        return $codec->digest(['schema' => 'billium.qualified-command-binding.v1', 'destination' => $destinationRef,
            'scope' => get_object_vars($scope), 'sourceLineage' => $o->sourceLineage, 'partitionRef' => $o->partitionRef,
            'commandSchema' => $message['schema'], 'commandType' => $message['commandType'], 'commandRef' => $message['commandRef'],
            'businessRef' => $message['businessRef'], 'providerAccountRef' => $o->providerAccountRef, 'payloadDigest' => $message['payloadDigest']]);
    }
}
