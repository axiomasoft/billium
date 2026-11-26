<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\CommandOutbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Data\Runtime\FactualCommandBinding;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Internal\Installation\Rows;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final class QualifiedCommandScope
{
    private const string TAG = 'billium.factual-command-scope.v1';

    public static function encode(FinancialScope $scope, string $lineage, string $partition, string $root, string $incarnation): string
    {
        return (new Codec)->canonicalize(['schema' => self::TAG, ...get_object_vars($scope),
            'installationIncarnation' => $incarnation, 'sourceLineage' => $lineage, 'partitionRef' => $partition, 'rootOperationRef' => $root]);
    }

    /** @param array<string,mixed> $message */
    public static function read(string $stored, string $destination, array $message): ?FinancialScope
    {
        $claims = json_decode($stored, false, 32, JSON_THROW_ON_ERROR);
        if (! $claims instanceof stdClass) {
            throw new LogicException('Invalid command scope');
        }
        if (! isset($claims->schema) && ($claims->channel ?? null) === 'events' && ($claims->sourceAuthority ?? null) === 'billium'
            && ! property_exists($claims, 'intentOwnerNamespace')) {
            $legacy = new FeedScope(Rows::string($claims->installationRef ?? null),
                Rows::string($claims->sellerRef ?? null), Rows::string($claims->environment ?? null), Rows::string($claims->lineage ?? null));
            if ($legacy->key() !== (new Codec)->canonicalize($claims)) {
                throw new LogicException('Invalid legacy command source scope');
            }

            return null;
        }
        $scope = new FinancialScope(Rows::string($claims->installationRef ?? null), Rows::string($claims->sellerRef ?? null),
            Rows::string($claims->environment ?? null), Rows::string($claims->intentOwnerNamespace ?? null), Rows::string($claims->sourceAuthority ?? null));
        $body = $message['payload'] ?? null;
        if (! $body instanceof stdClass || self::encode($scope, Rows::string($body->sourceLineage ?? null),
            Rows::string($body->partitionRef ?? null), Rows::string($message['businessRef'] ?? null), Rows::string($claims->installationIncarnation ?? null)) !== (new Codec)->canonicalize($claims)) {
            throw new LogicException('Tagged command origin differs from stored content');
        }
        FactualCommandBinding::digest($destination, $message, $scope);

        return $scope;
    }
}
