<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use BackedEnum;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelope;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use LogicException;
use Packman\Attributes\Internal;
use ReflectionClass;
use ReflectionNamedType;

/** Bounded data-only encoding. Class names never come from stored input. */
#[Internal]
final class ExecutionCodec
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::value($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function digest(mixed $value): string
    {
        return 'sha256:'.hash('sha256', self::encode($value));
    }

    public static function businessIdentityDigest(FrozenBinding $binding, string $logicalKind): string
    {
        return 'sha256:'.hash('sha256', (new Codec)->canonicalize([
            'schema' => 'billium.execution.business-identity.v1',
            'intentOwnerNamespace' => $binding->scope->intentOwnerNamespace,
            'environment' => $binding->scope->environment,
            'sourceContext' => $binding->sourceContext,
            'businessIntentRef' => $binding->businessIntentRef,
            'operationKind' => $logicalKind,
        ]));
    }

    public static function bindingDigest(FrozenBinding $binding): string
    {
        return AuthorityClaims::bindingDigest($binding);
    }

    public static function value(mixed $value): mixed
    {
        if ($value instanceof Envelope) {
            return self::value($value->raw);
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value instanceof FrozenBinding && $value->origin === null) {
            $value = get_object_vars($value);
            unset($value['origin']);
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::value(...), $value);
        }
        if (is_float($value)) {
            throw new JsonException('Floating point is not an execution storage value');
        }

        return $value;
    }

    /** @return array<string,mixed> */
    public static function map(string $json): array
    {
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data) || (array_is_list($data) && $data !== [])) {
            throw new LogicException('Expected stored execution object');
        }
        $mapped = [];
        foreach (array_keys($data) as $key) {
            if (! is_string($key)) {
                throw new LogicException('Expected string keys');
            }
            $mapped[$key] = $data[$key];
        }

        return $mapped;
    }

    /** @template T of object
     * @param  class-string<T>  $type
     * @return T
     */
    public static function decode(string $type, string $json): object
    {
        return self::hydrate($type, self::map($json));
    }

    /** @template T of object
     * @param  class-string<T>  $type
     * @param  array<string,mixed>  $data
     * @return T
     */
    private static function hydrate(string $type, array $data): object
    {
        $reflection = new ReflectionClass($type);
        $arguments = [];
        $parameters = $reflection->getConstructor()?->getParameters() ?? [];
        $strictBinding = in_array($type, [FrozenBinding::class, OriginDecision::class, ExecutionScope::class], true);
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            if (! array_key_exists($name, $data)) {
                if ($type === FrozenBinding::class && $name === 'origin') {
                    $arguments[] = null;

                    continue;
                }
                throw new LogicException('Missing stored field '.$name);
            }
            $value = $data[$name];
            unset($data[$name]);
            $hint = $parameter->getType();
            if ($strictBinding && $hint instanceof ReflectionNamedType) {
                $valid = $value === null ? $hint->allowsNull() : match ($hint->getName()) {
                    'string' => is_string($value),
                    'int' => is_int($value),
                    default => is_array($value) && ! array_is_list($value),
                };
                if (! $valid) {
                    throw new LogicException('Invalid stored original binding field '.$name);
                }
            }
            if ($value !== null && $hint instanceof ReflectionNamedType && ! $hint->isBuiltin()) {
                $nested = $hint->getName();
                if ($nested === DateTimeImmutable::class) {
                    $value = new DateTimeImmutable(self::text($value));
                } elseif (is_subclass_of($nested, BackedEnum::class)) {
                    $value = $nested::from(self::text($value));
                } elseif (class_exists($nested)) {
                    $value = self::decode($nested, self::encode($value));
                }
            } elseif ($name === 'supportedEffects' && is_array($value)) {
                $value = array_map(static fn (mixed $effect): EffectKind => EffectKind::from(self::text($effect)), $value);
            }
            $arguments[] = $value;
        }

        if ($strictBinding && $data !== []) {
            throw new LogicException('Unrecognized stored original binding fields');
        }

        return $reflection->newInstanceArgs($arguments);
    }

    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Expected stored text');
    }
}
