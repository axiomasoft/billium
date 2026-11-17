<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Closure;
use Packman\Attributes\Api;
use RuntimeException;

/** Trusted test data only. Store this file outside every database backup/restore path. */
#[Api(since: '1.0')]
final readonly class DurableJournal
{
    public function __construct(public string $path)
    {
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create external journal directory');
        }
    }

    /** @return list<object> */
    public function records(): array
    {
        return $this->transact(static fn (array $records): array => ['value' => $records, 'append' => []]);
    }

    public function append(object $record): void
    {
        $this->transact(static fn (array $records): array => ['value' => null, 'append' => [$record]]);
    }

    /**
     * @template T
     *
     * @param  Closure(list<object>): array{value:T,append:list<object>}  $work
     * @param  null|Closure():void  $beforeUnlock
     * @return T
     */
    public function transact(Closure $work, ?Closure $beforeUnlock = null): mixed
    {
        $stream = fopen($this->path, 'c+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot open external journal');
        }
        try {
            if (! flock($stream, LOCK_EX)) {
                throw new RuntimeException('Cannot lock external journal');
            }
            $records = [];
            while (($line = fgets($stream)) !== false) {
                $parts = explode(' ', trim($line), 2);
                if (count($parts) !== 2 || ! hash_equals(hash('sha256', $parts[1]), $parts[0])) {
                    throw new RuntimeException('Corrupt external journal');
                }
                $bytes = base64_decode($parts[1], true);
                if ($bytes === false) {
                    throw new RuntimeException('Corrupt external journal encoding');
                }
                $record = unserialize($bytes);
                if (! is_object($record)) {
                    throw new RuntimeException('Corrupt external journal record');
                }
                $records[] = $record;
            }
            $result = $work($records);
            foreach ($result['append'] as $record) {
                $payload = base64_encode(serialize($record));
                $line = hash('sha256', $payload).' '.$payload."\n";
                if (fwrite($stream, $line) !== strlen($line)) {
                    throw new RuntimeException('Short external journal write');
                }
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('External journal durability failure');
            }
            $beforeUnlock?->__invoke();

            return $result['value'];
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}
