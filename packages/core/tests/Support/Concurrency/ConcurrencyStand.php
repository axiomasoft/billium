<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support\Concurrency;

use Billium\Core\Tests\TestEnvironment;
use InvalidArgumentException;
use RuntimeException;

final class ConcurrencyStand
{
    /**
     * @param  class-string<Scenario>  $scenario
     * @param  array<string, mixed>  $args
     * @return list<array{index: int, ok?: array<string, mixed>, error?: array{code: string}}>
     */
    public static function run(string $scenario, int $workers, array $args = [], int $timeoutSeconds = 30): array
    {
        if ($workers < 1 || $timeoutSeconds < 1 || ! is_subclass_of($scenario, Scenario::class)) {
            throw new InvalidArgumentException('Expected a Scenario, positive worker count and positive timeout');
        }
        if (! function_exists('proc_open')) {
            throw new RuntimeException('M-33: proc_open is required for concurrency proofs');
        }
        $arguments = base64_encode(json_encode($args, JSON_THROW_ON_ERROR));
        $root = dirname(__DIR__, 5);
        $barrier = TestEnvironment::pdo(owner: true);
        $key = random_int(1, 2_147_483_647);
        // D50: test coordination only; this lock is never financial writer fencing.
        $lock = $barrier->prepare('SELECT pg_advisory_lock(?)');
        $lock->execute([$key]);
        $released = false;
        $children = [];
        $deadline = microtime(true) + $timeoutSeconds;
        try {
            for ($index = 0; $index < $workers; $index++) {
                $process = proc_open([
                    PHP_BINARY, $root.'/packages/core/tests/Support/Concurrency/bin/worker.php',
                    $scenario, (string) $index, $arguments, (string) $key,
                ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
                if ($process === false) {
                    throw new RuntimeException("Unable to start worker {$index}");
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $children[$index] = ['process' => $process, 'pipes' => $pipes, 'stdout' => '', 'stderr' => '', 'exit' => null];
            }

            $waiting = $barrier->prepare(<<<'SQL'
                SELECT count(*) FROM pg_locks
                WHERE locktype = 'advisory' AND classid = 0 AND objid = :key AND objsubid = 1
                  AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
                  AND mode = 'ShareLock' AND NOT granted
                SQL);
            $barrierDeadline = min($deadline, microtime(true) + 20);
            do {
                self::collect($children);
                foreach ($children as $index => $child) {
                    if ($child['exit'] !== null) {
                        throw new RuntimeException("Worker {$index} exited before the barrier: ".self::diagnostics($children));
                    }
                }
                $waiting->execute(['key' => $key]);
                if ((int) $waiting->fetchColumn() === $workers) {
                    break;
                }
                if (microtime(true) >= $barrierDeadline) {
                    throw new RuntimeException('Timed out waiting for all workers at advisory barrier: '.self::diagnostics($children));
                }
                usleep(20_000);
            } while (true);

            $unlock = $barrier->prepare('SELECT pg_advisory_unlock(?)');
            $unlock->execute([$key]);
            $released = true;
            do {
                self::collect($children);
                $running = false;
                foreach ($children as $index => $child) {
                    $running = $running || $child['exit'] === null;
                    if ($child['exit'] !== null && $child['exit'] !== 0) {
                        throw new RuntimeException("Worker {$index} exited {$child['exit']}: ".self::diagnostics($children));
                    }
                }
                if (! $running) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out running workers: '.self::diagnostics($children));
                }
                usleep(20_000);
            } while (true);

            $results = [];
            foreach ($children as $index => $child) {
                try {
                    $result = json_decode(trim($child['stdout']), true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new RuntimeException("Worker {$index} returned invalid JSON: ".self::diagnostics($children), previous: $exception);
                }
                if (! is_array($result) || ($result['index'] ?? null) !== $index
                    || (isset($result['ok']) === isset($result['error']))
                    || (isset($result['ok']) && ! is_array($result['ok']))
                    || (isset($result['error']) && ! is_string($result['error']['code'] ?? null))) {
                    throw new RuntimeException("Worker {$index} returned an invalid result: ".self::diagnostics($children));
                }
                $results[] = $result;
            }

            return $results;
        } finally {
            // Kill before releasing a failed barrier so a timed-out run cannot execute later.
            // A killed PHP process alone does not interrupt a PostgreSQL query such as pg_sleep.
            $cancel = $barrier->prepare(<<<'SQL'
                SELECT pg_terminate_backend(pid) FROM pg_locks
                WHERE locktype = 'advisory' AND classid = 0 AND objid = :key AND objsubid = 1
                  AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
                  AND mode = 'ShareLock'
                SQL);
            $cancel->execute(['key' => $key]);
            foreach ($children as $child) {
                if (proc_get_status($child['process'])['running']) {
                    proc_terminate($child['process'], 9);
                }
                fclose($child['pipes'][1]);
                fclose($child['pipes'][2]);
                proc_close($child['process']);
            }
            if (! $released) {
                $unlock = $barrier->prepare('SELECT pg_advisory_unlock(?)');
                $unlock->execute([$key]);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $children */
    private static function collect(array &$children): void
    {
        foreach ($children as &$child) {
            $child['stdout'] .= stream_get_contents($child['pipes'][1]);
            $child['stderr'] .= stream_get_contents($child['pipes'][2]);
            if (strlen($child['stdout']) + strlen($child['stderr']) > 1_048_576) {
                throw new RuntimeException('Worker output exceeds 1 MiB');
            }
            if ($child['exit'] === null) {
                $status = proc_get_status($child['process']);
                if (! $status['running']) {
                    $child['exit'] = $status['exitcode'];
                    $child['stdout'] .= stream_get_contents($child['pipes'][1]);
                    $child['stderr'] .= stream_get_contents($child['pipes'][2]);
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $children */
    private static function diagnostics(array $children): string
    {
        return implode(' | ', array_map(
            static fn (array $child): string => 'exit='.($child['exit'] ?? 'running').' stderr='.trim($child['stderr']).' stdout='.trim($child['stdout']),
            $children,
        ));
    }
}
