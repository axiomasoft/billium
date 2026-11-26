<?php

declare(strict_types=1);

namespace Billium\Platform\Data\EventContracts;

use InvalidArgumentException;
use Packman\Attributes\Api;

/** Immutable opt-in routes. Resolving a destination is a local data lookup within the producer transaction. */
#[Api(since: '1.0')]
final readonly class PublicEventDestinations
{
    /** @var array<string,list<string>> */
    private array $byContract;

    /** @param array<mixed> $byContract */
    public function __construct(array $byContract)
    {
        if (count($byContract) > 1024) {
            throw new InvalidArgumentException('Public event destination catalog exceeds its bound');
        }
        $normalized = [];
        foreach ($byContract as $key => $destinations) {
            if (! is_string($key) || ! is_array($destinations)) {
                throw new InvalidArgumentException('Destination catalog requires string contract keys and list values');
            }
            $parts = explode('|', $key);
            if (count($parts) !== 3 || ! array_is_list($destinations) || count($destinations) < 1 || count($destinations) > 100) {
                throw new InvalidArgumentException('Destination catalog requires exact registered contracts and bounded nonempty lists');
            }
            foreach ([...$parts, ...$destinations] as $value) {
                if (! is_string($value) || trim($value) === '' || strlen($value) > 200 || str_contains($value, "\0")) {
                    throw new InvalidArgumentException('Public event contract and destination references must be bounded');
                }
            }
            $routes = [];
            foreach ($destinations as $destination) {
                if (! is_string($destination)) {
                    throw new InvalidArgumentException('A destination must be a string');
                }
                $routes[] = $destination;
            }
            if (count(array_unique($routes, SORT_STRING)) !== count($routes)) {
                throw new InvalidArgumentException('Public event destinations must be unique');
            }
            $normalized[$key] = $routes;
        }
        $this->byContract = $normalized;
    }

    /** @return list<string>|null */
    public function forContract(EventContractRegistration $contract): ?array
    {
        return $this->byContract[$contract->key()] ?? null;
    }
}
