<?php

declare(strict_types=1);

namespace Billium\Testkit\Faults;

use Packman\Attributes\Api;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Deterministic seeded fault-injection plan for repeatable failure and conservation scenarios.
 */
#[Api(since: '1.0')]
final class SeededFaultPlan
{
    private readonly Randomizer $randomizer;

    /** @var array<string, int> */
    private array $triggered = [];

    /**
     * @param  array<string, int>  $remaining
     */
    public function __construct(
        public readonly int $seed,
        public readonly string $scenarioId,
        private array $remaining = [],
    ) {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /**
     * @param  array<string, int>  $failpoints
     */
    public static function forScenario(string $scenarioId, int $seed = 20261014, array $failpoints = []): self
    {
        return new self($seed, $scenarioId, $failpoints);
    }

    /**
     * Deterministically partitions a positive minor-unit total into N positive parts that sum exactly to total.
     *
     * @return list<int>
     */
    public function splitAmount(int $totalMinor, int $parts, int $minPart = 100): array
    {
        if ($parts < 1 || $minPart < 1 || $totalMinor < $parts * $minPart) {
            throw new RuntimeException('Invalid seeded amount partition parameters');
        }
        $result = [];
        $remaining = $totalMinor;
        for ($i = 0; $i < $parts - 1; $i++) {
            $max = $remaining - (($parts - $i - 1) * $minPart);
            $slice = $this->randomizer->getInt($minPart, $max);
            $result[] = $slice;
            $remaining -= $slice;
        }
        $result[] = $remaining;

        return $result;
    }

    /**
     * Consumes one activation of the named failpoint when armed.
     */
    public function trigger(string $point): bool
    {
        $left = $this->remaining[$point] ?? 0;
        if ($left <= 0) {
            return false;
        }
        $this->remaining[$point] = $left - 1;
        $this->triggered[$point] = ($this->triggered[$point] ?? 0) + 1;

        return true;
    }

    public function recordEvent(string $name): void
    {
        $this->triggered[$name] = ($this->triggered[$name] ?? 0) + 1;
    }

    /**
     * @return array<string, int>
     */
    public function triggeredCounts(): array
    {
        ksort($this->triggered);

        return $this->triggered;
    }

    /**
     * @return array{scenarioId: string, seed: int, triggered: array<string, int>}
     */
    public function summary(): array
    {
        return [
            'scenarioId' => $this->scenarioId,
            'seed' => $this->seed,
            'triggered' => $this->triggeredCounts(),
        ];
    }
}
