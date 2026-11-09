<?php

declare(strict_types=1);

namespace Billium\Contracts\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Envelope;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Data\Problem;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Contracts\Internal\Jcs;
use Billium\Contracts\Internal\Json;
use Billium\Contracts\Internal\P4Wire;
use Billium\Contracts\Internal\SchemaValidator;
use Billium\Contracts\Internal\Spec;
use stdClass;

/**
 * Strict decode and canonical encode of the envelopes of the spec revision, plus the digest of D32.
 */
#[Api]
final readonly class Codec
{
    /** @param list<string>|null $supportedSchemas Explicit client capabilities; null uses this generated revision. */
    public function __construct(private ?array $supportedSchemas = null) {}

    private const string ENVELOPES = 'Billium\\Contracts\\Data\\Envelopes\\';

    private const string PROBLEM_SCHEMA = 'urn:billium:spec:2026-10:problem/problem.json';

    /**
     * Decode checks in order: JSON syntax, `schema`, fields, amounts (BC-ENV-03).
     *
     * @throws ProblemException billium.validation.invalid, billium.schema.unsupported or billium.money.scale_invalid
     */
    public function decode(string $json): Envelope
    {
        $value = Json::decode($json);
        if (! $value instanceof stdClass || ! is_string($value->schema ?? null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'an envelope is an object with a string schema');
        }
        if ($this->supportedSchemas !== null && ! in_array($value->schema, $this->supportedSchemas, true)) {
            throw new ProblemException(ProblemCode::SchemaUnsupported, 'Schema is outside the pinned client capabilities');
        }
        $entry = Spec::envelopes()[$value->schema]
            ?? throw new ProblemException(ProblemCode::SchemaUnsupported, "schema '{$value->schema}' is not in the revision");

        if ($value->schema === 'billium.event.v2' && ($value->eventContractRevision ?? null) !== 'events-2026-10-v2') {
            throw new ProblemException(ProblemCode::SchemaUnsupported, 'unknown pinned event catalog');
        }

        $problem = new SchemaValidator(Spec::schemas())->check($entry['schemaId'], $value);
        if ($problem !== null) {
            throw new ProblemException($problem, "the object does not match {$value->schema}");
        }
        if (in_array($value->schema, P4Wire::SCHEMAS, true)) {
            P4Wire::validate($value);
            foreach (get_object_vars($value) as $child) {
                if ($child instanceof stdClass && isset($child->schema)) {
                    $this->decode(Jcs::canonicalize($child));
                }
            }
        }

        $effect = $value->schema === 'payment.effect.outcome.v1' ? $value : ($value->schema === 'billium.event.v2' ? $value->payload : null);
        if ($effect instanceof stdClass && $effect->operationRef === $effect->originalOperationRef) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'effect operationRef must differ from its root');
        }
        if ($value->schema === 'billium.event.v2' && $effect instanceof stdClass
            && ($value->sellerRef !== $effect->sellerRef || $value->aggregateRef !== $effect->operationRef || $value->revision !== $effect->revision)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'effect envelope identity differs from its payload');
        }

        $variant = $entry['variants'][0];
        foreach ($entry['variants'] as $candidate) {
            if (! isset($candidate['requires']) || property_exists($value, $candidate['requires'])) {
                $variant = $candidate;
                break;
            }
        }
        $member = $variant['digestMember'];
        if ($variant['verifyDigest'] && property_exists($value, $member) && $value->{$member} !== $this->digest($value, $member)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, "{$member} differs from the digest of the canonical form");
        }
        if ($value->schema === 'billium.feed.page.v1' && is_array($value->events)) {
            foreach ($value->events as $event) {
                // D63: a page cannot bypass the event codec's semantics or this client's negotiated capabilities.
                $this->decode(Jcs::canonicalize($event));
            }
        }

        /** @var class-string<Envelope> $class */
        $class = self::ENVELOPES.$variant['class'];

        return $class::fromRaw($value);
    }

    /**
     * Parses a MoneyWire JSON text.
     *
     * @throws ProblemException billium.money.scale_invalid for a form violation, billium.validation.invalid for a syntax error or beyond int64
     */
    public function decodeMoney(string $json): Money
    {
        return Money::fromWire(Json::decode($json));
    }

    /**
     * The canonical form (RFC 8785) of the envelope as received, `digest` included.
     */
    public function encode(Envelope $envelope): string
    {
        return Jcs::canonicalize($envelope->raw);
    }

    /**
     * The canonical form of a JSON value: a JSON text, a decoded stdClass, or a PHP array.
     *
     * @throws ProblemException billium.validation.invalid for a float, an unsafe integer or a syntax error
     */
    public function canonicalize(mixed $json): string
    {
        return Jcs::canonicalize(is_string($json) ? Json::decode($json) : $json);
    }

    /**
     * "sha256:" and the lowercase hex SHA-256 of the canonical form without the top-level `$member` (BC-ID-12, BC-ID-13).
     *
     * @param  string  $member  the digest member to leave out: `digest`, or `contentDigest` of payment.observation.v1
     */
    public function digest(mixed $json, string $member = 'digest'): string
    {
        $value = is_string($json) ? Json::decode($json) : $json;
        if ($value instanceof stdClass) {
            $value = clone $value;
            unset($value->{$member});
        } elseif (is_array($value) && ! array_is_list($value)) {
            unset($value[$member]);
        }

        return 'sha256:'.hash('sha256', Jcs::canonicalize($value));
    }

    /**
     * The digest of a decoded envelope, computed over the object as received (BC-ID-12).
     */
    public function digestOf(Envelope $envelope): string
    {
        $member = Spec::envelopes()[$envelope->schema]['variants'][0]['digestMember'];

        return $this->digest($envelope->raw, $member);
    }

    /**
     * @throws ProblemException billium.validation.invalid when the object breaks the problem schema or its registry entry
     */
    public function decodeProblem(string $json): Problem
    {
        $value = Json::decode($json);
        $code = new SchemaValidator(Spec::schemas())->check(self::PROBLEM_SCHEMA, $value);
        if ($code !== null || ! $value instanceof stdClass) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'not a problem object of the revision');
        }
        $registry = ProblemCode::tryFrom(is_string($value->code) ? $value->code : '')
            ?? throw new ProblemException(ProblemCode::ValidationInvalid, 'unknown problem code');
        if ($value->category !== $registry->category() || $value->retryable !== $registry->retryable()
            || $value->sameKey !== $registry->sameKey() || $value->receipt !== $registry->receipt()) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'the problem members differ from the registry entry');
        }

        return new Problem(
            code: $registry,
            title: is_string($value->title) ? $value->title : '',
            detail: is_string($value->detail ?? null) ? $value->detail : null,
            type: is_string($value->type ?? null) ? $value->type : null,
            status: is_int($value->status ?? null) ? $value->status : null,
            instance: is_string($value->instance ?? null) ? $value->instance : null,
        );
    }
}
