<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use LogicException;
use stdClass;

/**
 * Validates decoded JSON against the schemas of the revision (the keyword subset they use; any other keyword
 * is a LogicException, never a silent pass). Money nodes and operation totals do not fail the field stage:
 * their violation is deferred so that decode reports it after the other fields (BC-ENV-03, BC-MW-07).
 */
final class SchemaValidator
{
    private const string MONEY_DOCUMENT = 'common/money-wire.json';

    private const string TOTAL_DEFINITION = 'common/defs.json#/$defs/operationTotal';

    private const array IGNORED = ['$id', '$defs', 'then', 'else'];

    private ?ProblemCode $deferred = null;

    /**
     * @param  array<string, mixed>  $documents  schemas by `$id`
     */
    public function __construct(private readonly array $documents) {}

    /**
     * The code decode must raise for the instance, or null when it is valid.
     */
    public function check(string $documentId, mixed $instance): ?ProblemCode
    {
        $this->deferred = null;
        $document = $this->documents[$documentId] ?? throw new LogicException("unknown schema {$documentId}");
        if (! $this->valid($instance, $this->schema($document), $documentId, null)) {
            return ProblemCode::ValidationInvalid;
        }

        return $this->deferred;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function valid(mixed $instance, array $schema, string $document, ?stdClass $parent): bool
    {
        foreach ($schema as $keyword => $argument) {
            if (in_array($keyword, self::IGNORED, true)) {
                continue;
            }
            $ok = match ($keyword) {
                '$ref' => $this->reference($instance, $this->text($argument), $document, $parent),
                'type' => $this->type($instance, $argument),
                'const' => $instance === $argument,
                'enum' => in_array($instance, $this->listOf($argument), true),
                'pattern' => ! is_string($instance) || $this->matches($this->text($argument), $instance),
                'minLength' => ! is_string($instance) || mb_strlen($instance, 'UTF-8') >= $this->number($argument),
                'minimum' => ! is_int($instance) && ! is_float($instance) || $instance >= $this->number($argument),
                'maximum' => ! is_int($instance) && ! is_float($instance) || $instance <= $this->number($argument),
                'minItems' => ! is_array($instance) || count($instance) >= $this->number($argument),
                'items' => ! is_array($instance) || $this->items($instance, $this->schema($argument), $document),
                'required' => ! $instance instanceof stdClass || $this->required($instance, $this->listOf($argument)),
                'properties' => ! $instance instanceof stdClass || $this->properties($instance, $this->schema($argument), $document),
                'additionalProperties' => ! $instance instanceof stdClass || $this->additional($instance, $argument, $schema, $document),
                'minProperties' => ! $instance instanceof stdClass || count(get_object_vars($instance)) >= $this->number($argument),
                'allOf' => $this->all($instance, $this->listOf($argument), $document, $parent),
                'anyOf' => $this->any($instance, $this->listOf($argument), $document, $parent),
                'oneOf' => $this->one($instance, $this->listOf($argument), $document, $parent),
                'not' => ! $this->isolated($instance, $this->schema($argument), $document, $parent),
                'if' => $this->conditional($instance, $schema, $document, $parent),
                default => throw new LogicException("unsupported schema keyword {$keyword}"),
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function reference(mixed $instance, string $reference, string $document, ?stdClass $parent): bool
    {
        [$target, $pointer] = array_pad(explode('#', $reference, 2), 2, '');
        $targetDocument = $target === '' ? $document : $target;
        $node = $this->documents[$targetDocument] ?? throw new LogicException("unknown schema {$targetDocument}");
        foreach (array_filter(explode('/', $pointer), static fn (string $part): bool => $part !== '') as $part) {
            $node = is_array($node) && array_key_exists($part, $node) ? $node[$part] : throw new LogicException("bad pointer {$reference}");
        }
        $schema = $this->schema($node);

        $entersMoney = str_ends_with($targetDocument, self::MONEY_DOCUMENT) && ! str_ends_with($document, self::MONEY_DOCUMENT);
        if ($entersMoney) {
            return $this->money($instance, $schema, $targetDocument, $parent);
        }
        if (str_ends_with($targetDocument.'#'.$pointer, self::TOTAL_DEFINITION)) {
            return $this->total($instance, $parent);
        }

        return $this->valid($instance, $schema, $targetDocument, $parent);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function money(mixed $instance, array $schema, string $document, ?stdClass $parent): bool
    {
        try {
            Money::fromWire($instance);
        } catch (ProblemException $exception) {
            $this->deferred ??= $exception->problem;

            return true;
        }

        return $this->valid($instance, $schema, $document, $parent);
    }

    /**
     * A cumulative total of payment.outcome: a decimal string with the scale of the sibling `money` currency.
     */
    private function total(mixed $instance, ?stdClass $parent): bool
    {
        $scale = null;
        $money = $parent->money ?? null;
        if ($money instanceof stdClass && is_string($money->currency ?? null)) {
            $scale = CurrencyTable::find($money->currency)?->scale;
        }
        $fraction = $scale === null ? '(\.[0-9]+)?' : ($scale === 0 ? '' : '\.[0-9]{'.$scale.'}');
        if (! is_string($instance) || preg_match('/^(0|[1-9][0-9]*)'.$fraction.'$/D', $instance) !== 1) {
            $this->deferred ??= ProblemCode::MoneyScaleInvalid;
        }

        return true;
    }

    private function type(mixed $instance, mixed $type): bool
    {
        foreach (is_array($type) ? $type : [$type] as $name) {
            $matches = match ($name) {
                'object' => $instance instanceof stdClass,
                'array' => is_array($instance),
                'string' => is_string($instance),
                'integer' => is_int($instance),
                'number' => is_int($instance) || is_float($instance),
                'boolean' => is_bool($instance),
                'null' => $instance === null,
                default => throw new LogicException('unsupported schema type'),
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $pattern, string $value): bool
    {
        if (str_contains($pattern, '~')) {
            throw new LogicException('schema pattern contains the delimiter');
        }

        return preg_match('~'.$pattern.'~uD', $value) === 1;
    }

    /**
     * @param  array<mixed>  $items
     * @param  array<string, mixed>  $schema
     */
    private function items(array $items, array $schema, string $document): bool
    {
        return array_all($items, fn ($item) => $this->valid($item, $schema, $document, null));
    }

    /**
     * @param  list<mixed>  $names
     */
    private function required(stdClass $instance, array $names): bool
    {
        return array_all($names, fn ($name) => ! (! is_string($name) || ! property_exists($instance, $name)));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function properties(stdClass $instance, array $properties, string $document): bool
    {
        return array_all($properties, fn ($schema, $name) => ! (property_exists($instance, (string) $name) && ! $this->valid($instance->{$name}, $this->schema($schema), $document, $instance)));
    }

    /**
     * @param  array<string, mixed>  $schema  the schema that holds the keyword
     */
    private function additional(stdClass $instance, mixed $rule, array $schema, string $document): bool
    {
        $declared = array_map(strval(...), array_keys($this->schema($schema['properties'] ?? [])));
        foreach (array_keys(get_object_vars($instance)) as $name) {
            if (in_array((string) $name, $declared, true)) {
                continue;
            }
            if ($rule === false || is_array($rule) && ! $this->valid($instance->{$name}, $this->schema($rule), $document, null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $schemas
     */
    private function all(mixed $instance, array $schemas, string $document, ?stdClass $parent): bool
    {
        return array_all($schemas, fn ($schema) => $this->valid($instance, $this->schema($schema), $document, $parent));
    }

    /**
     * @param  list<mixed>  $schemas
     */
    private function any(mixed $instance, array $schemas, string $document, ?stdClass $parent): bool
    {
        return array_any($schemas, fn ($schema) => $this->branch($instance, $this->schema($schema), $document, $parent));
    }

    /**
     * @param  list<mixed>  $schemas
     */
    private function one(mixed $instance, array $schemas, string $document, ?stdClass $parent): bool
    {
        $valid = 0;
        $kept = $this->deferred;
        $deferred = null;
        foreach ($schemas as $schema) {
            $this->deferred = $kept;
            if ($this->branch($instance, $this->schema($schema), $document, $parent)) {
                $valid++;
                $deferred = $this->deferred;
            }
        }
        $this->deferred = $valid === 1 ? $deferred : $kept;

        return $valid === 1;
    }

    /**
     * @param  array<string, mixed>  $schema  the schema that holds the `if`
     */
    private function conditional(mixed $instance, array $schema, string $document, ?stdClass $parent): bool
    {
        $branch = $this->isolated($instance, $this->schema($schema['if']), $document, $parent) ? 'then' : 'else';
        if (! array_key_exists($branch, $schema)) {
            return true;
        }

        return $this->valid($instance, $this->schema($schema[$branch]), $document, $parent);
    }

    /**
     * Evaluates a branch whose deferred money violation survives only when the branch is valid.
     *
     * @param  array<string, mixed>  $schema
     */
    private function branch(mixed $instance, array $schema, string $document, ?stdClass $parent): bool
    {
        $kept = $this->deferred;
        $ok = $this->valid($instance, $schema, $document, $parent);
        if (! $ok) {
            $this->deferred = $kept;
        }

        return $ok;
    }

    /**
     * Evaluates a condition without leaving a deferred violation behind.
     *
     * @param  array<string, mixed>  $schema
     */
    private function isolated(mixed $instance, array $schema, string $document, ?stdClass $parent): bool
    {
        $kept = $this->deferred;
        $ok = $this->valid($instance, $schema, $document, $parent);
        $this->deferred = $kept;

        return $ok;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(mixed $node): array
    {
        if (! is_array($node)) {
            throw new LogicException('a schema must be an object');
        }

        /** @var array<string, mixed> $node */
        return $node;
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $node): array
    {
        if (! is_array($node) || ! array_is_list($node)) {
            throw new LogicException('a schema keyword expects a list');
        }

        return $node;
    }

    private function text(mixed $node): string
    {
        return is_string($node) ? $node : throw new LogicException('a schema keyword expects a string');
    }

    private function number(mixed $node): int|float
    {
        return is_int($node) || is_float($node) ? $node : throw new LogicException('a schema keyword expects a number');
    }
}
