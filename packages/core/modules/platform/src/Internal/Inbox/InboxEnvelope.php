<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Inbox;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Caller;
use stdClass;

/**
 * The custody-relevant fields of a decoded observation envelope. Nothing here proves authenticity: the owning facade
 * decodes and verifies the transport; custody only refuses what is outside the authenticated caller's scope.
 */
final readonly class InboxEnvelope
{
    public const int MAX_BYTES = 65536;

    private function __construct(
        public string $channel,
        public string $eventId,
        public string $contentDigest,
        public string $sourceAuthority,
        public string $sourceLineage,
        public string $partitionRef,
        public int $sourceSequence,
        public string $sellerRef,
        public string $environment,
        public string $operationRef,
        public int $operationRevision,
        public ?string $installationRef,
        public string $json,
    ) {}

    /** @throws ProblemException billium.validation.invalid */
    public static function from(string $channel, stdClass $envelope): self
    {
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $channel) !== 1 || strlen($channel) > 200) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Custody channel is a lowercase dotted name');
        }
        $json = json_encode($envelope, JSON_THROW_ON_ERROR);
        $digest = self::text($envelope, 'contentDigest');
        if (strlen($json) > self::MAX_BYTES || preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Envelope exceeds the custody bound or has no sha256 contentDigest');
        }
        $sequence = $envelope->sourceSequence ?? null;
        $revision = $envelope->operationRevision ?? null;
        if (! is_int($sequence) || $sequence < 1 || ! is_int($revision) || $revision < 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceSequence starts at 1 and operationRevision is not negative');
        }
        $installation = $envelope->installationRef ?? null;

        return new self(
            $channel, self::text($envelope, 'eventId'), $digest, self::text($envelope, 'sourceAuthority'),
            self::text($envelope, 'sourceLineage'), self::text($envelope, 'partitionRef'), $sequence,
            self::text($envelope, 'sellerRef'), self::text($envelope, 'environment'), self::text($envelope, 'operationRef'),
            $revision, is_string($installation) ? $installation : null, $json,
        );
    }

    /** The source namespace must be the authenticated caller's own and granted: no omission, no foreign seller (INV-14). */
    public function authorize(Caller $caller): void
    {
        if ($this->sellerRef !== $caller->sellerRef || $this->environment !== $caller->environment
            || ($this->installationRef !== null && $this->installationRef !== $caller->installationRef)
            || ! in_array($this->sourceAuthority, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'The delivery is outside the authenticated source scope');
        }
    }

    private static function text(stdClass $envelope, string $field): string
    {
        $value = $envelope->{$field} ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > 200) {
            throw new ProblemException(ProblemCode::ValidationInvalid, "Envelope field {$field} is a nonempty bounded string");
        }

        return $value;
    }
}
