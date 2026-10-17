<?php

declare(strict_types=1);

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\MoneyMath;
use Billium\Contracts\Contracts\MoneyMathFactory;
use Billium\Contracts\Contracts\SpecRevision;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Enums\RoundingMode;
use Billium\Contracts\Exceptions\ProblemException;

/*
 * Conformance adapter of billium-contracts for PHP (protocol: conformance/README.md).
 * JSON Lines on stdin/stdout, diagnostics on stderr; it uses the public API of the package only.
 */

require dirname(__DIR__, 4).'/vendor/autoload.php';

const OPS = [
    'money.parse', 'money.format', 'money.add', 'money.allocate', 'money.round',
    'canon.canonicalize', 'canon.digest', 'envelope.decode', 'envelope.roundtrip',
    'problem.decode', 'codes.registry',
];

/**
 * @param  array<string, mixed>  $message
 */
function send(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
}

function invalid(string $detail): ProblemException
{
    return new ProblemException(ProblemCode::ValidationInvalid, $detail);
}

function text(stdClass $args, string $name): string
{
    $value = $args->{$name} ?? null;

    return is_string($value) ? $value : throw invalid("argument {$name} must be a string");
}

/**
 * @return array<string, mixed>
 */
function run(Codec $codec, MoneyMath $math, string $op, stdClass $args): array
{
    switch ($op) {
        case 'money.parse':
            $money = $codec->decodeMoney(text($args, 'wire_text'));

            return ['minor' => (string) $money->minor, 'currency' => $money->currency->code];
        case 'money.format':
            $minor = text($args, 'minor');
            if (preg_match('/^-?(0|[1-9][0-9]*)$/D', $minor) !== 1) {
                throw invalid('minor must be an integer string');
            }
            if ((string) (int) $minor !== $minor) {
                throw invalid('minor is outside the int64 range');
            }

            return ['wire' => (new Money((int) $minor, Currency::of(text($args, 'currency'))))->toWire()];
        case 'money.add':
            return ['wire' => $math->add(Money::fromWire($args->a ?? null), Money::fromWire($args->b ?? null))->toWire()];
        case 'money.allocate':
            $ratios = $args->ratios ?? null;
            $parts = $math->allocate(Money::fromWire($args->total ?? null), is_array($ratios) ? $ratios : throw invalid('ratios must be a list'));

            return ['parts' => array_map(static fn (Money $part): array => $part->toWire(), $parts)];
        case 'money.round':
            $mode = RoundingMode::tryFrom(text($args, 'mode')) ?? throw invalid('unknown rounding mode');

            return ['wire' => $math->round(text($args, 'value'), Currency::of(text($args, 'currency')), $mode)->toWire()];
        case 'canon.canonicalize':
            return ['text' => $codec->canonicalize(text($args, 'json_text'))];
        case 'canon.digest':
            return ['digest' => $codec->digest(text($args, 'json_text'))];
        case 'envelope.decode':
            $envelope = $codec->decode(text($args, 'json_text'));

            return ['schema' => $envelope->schema, 'digest' => $codec->digestOf($envelope)];
        case 'envelope.roundtrip':
            return ['text' => $codec->encode($codec->decode(text($args, 'json_text')))];
        case 'problem.decode':
            $problem = $codec->decodeProblem(text($args, 'json_text'));

            return [
                'code' => $problem->code->value,
                'category' => $problem->code->category(),
                'retryable' => $problem->code->retryable(),
                'sameKey' => $problem->code->sameKey(),
                'receipt' => $problem->code->receipt(),
            ];
        case 'codes.registry':
            return ['codes' => array_map(static fn (ProblemCode $code): array => [
                'code' => $code->value,
                'category' => $code->category(),
                'retryable' => $code->retryable(),
                'sameKey' => $code->sameKey(),
                'receipt' => $code->receipt(),
            ], ProblemCode::cases())];
        default:
            throw new LogicException("unknown op {$op}");
    }
}

$codec = new Codec;
$math = MoneyMathFactory::create();

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
    if (! $message instanceof stdClass) {
        throw new UnexpectedValueException('a message is a JSON object');
    }
    switch ($message->type) {
        case 'start':
            send(['type' => 'ready', 'adapter' => 'php', 'spec_rev' => SpecRevision::CURRENT, 'ops' => OPS]);
            break;
        case 'config':
            send(['type' => 'ok']);
            break;
        case 'run':
            $outputs = [];
            foreach ($message->script as $step) {
                try {
                    $outputs[] = ['ok' => run($codec, $math, $step->op, $step->args)];
                } catch (ProblemException $exception) {
                    $outputs[] = ['error' => ['code' => $exception->problem->value, 'detail' => $exception->detail]];
                }
            }
            send(['type' => 'result', 'id' => $message->id, 'outputs' => $outputs]);
            break;
        case 'stop':
            exit(0);
        default:
            throw new UnexpectedValueException("unknown message type {$message->type}");
    }
}
