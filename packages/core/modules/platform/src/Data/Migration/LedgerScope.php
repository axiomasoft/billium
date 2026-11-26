<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Billium\Core\Data\Runtime\OwnerSessionIdentity;
use Billium\Platform\Data\Feed\FeedScope;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class LedgerScope
{
    /**
     * @param  array<array-key,string>  $books
     * @param  array<array-key,string>  $entityRefs
     */
    public function __construct(public FeedScope $feed, public OwnerSessionIdentity $owner, public array $books, public array $entityRefs)
    {
        if ($feed->installationRef !== $owner->installationRef) {
            throw new InvalidArgumentException('Owner installation differs from ledger scope');
        }
        foreach ([$books, $entityRefs] as $selection) {
            if ($selection === [] || ! array_is_list($selection)) {
                throw new InvalidArgumentException('Ledger selection must be explicit');
            }
            $previous = '';
            foreach ($selection as $ref) {
                if (trim($ref) === '' || strlen($ref) > 200 || strcmp($previous, $ref) >= 0) {
                    throw new InvalidArgumentException('Ledger selection must be sorted, unique and explicit');
                }
                $previous = $ref;
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['feed' => $this->feed->toArray(), 'owner' => get_object_vars($this->owner), 'books' => $this->books, 'entityRefs' => $this->entityRefs];
    }
}
