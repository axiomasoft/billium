<?php

declare(strict_types=1);

namespace Billium\Testkit\Restore;

use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\QualifiedFactualCommandPublisher;
use Billium\Core\Data\Runtime\FactualCommandBinding;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Data\Runtime\SourceAuthorityGrant;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Closure;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Api;

/** Simulated detached transport. The producer signs its qualified outbox emission. The receiver authenticates that journal in another process. */
#[Api(since: '1.0')]
final readonly class SignedCommandChannel implements IncomingCommandAuthenticator, QualifiedFactualCommandPublisher
{
    /** @param Closure(string):void|null $deliver */
    public function __construct(private DurableJournal $journal, private string $key, private ?Closure $deliver = null) {}

    public function publish(string $destinationRef, array $message): void
    {
        throw new LogicException('Only qualified factual commands can cross this fixture channel');
    }

    public function publishQualified(string $destinationRef, array $message, FinancialScope $scope): void
    {
        $binding = FactualCommandBinding::digest($destinationRef, $message, $scope);
        $body = base64_encode(serialize([$destinationRef, $message, $scope]));
        $this->journal->append(new JournalEvent('command', ['binding' => $binding, 'body' => $body,
            'mac' => hash_hmac('sha256', $body, $this->key)], ProcessIdentity::current()));
        ($this->deliver ?? throw new LogicException('Fixture delivery unavailable'))($binding);
    }

    /** @return array{string,array<string,mixed>,FinancialScope} */
    public function packet(string $binding): array
    {
        foreach ($this->journal->records() as $row) {
            if ($row instanceof JournalEvent && $row->kind === 'command' && $row->data['binding'] === $binding) {
                $body = $row->data['body'];
                if (! is_string($body) || ! hash_equals(hash_hmac('sha256', $body, $this->key), (string) $row->data['mac'])) {
                    throw new LogicException('Forged command packet');
                }

                $packet = unserialize((string) base64_decode($body, true));
                if (! is_array($packet) || ! is_string($packet[0] ?? null) || ! is_array($packet[1] ?? null) || ! ($packet[2] ?? null) instanceof FinancialScope) {
                    throw new LogicException('Invalid signed command packet');
                }
                $message = [];
                foreach ($packet[1] as $field => $value) {
                    if (! is_string($field)) {
                        throw new LogicException('Invalid command field');
                    }
                    $message[$field] = $value;
                }

                return [$packet[0], $message, $packet[2]];
            }
        }
        throw new LogicException('Missing independently authenticated producer packet');
    }

    public function authenticate(string $destinationRef, array $message): SourceAuthorityGrant
    {
        foreach ($this->journal->records() as $row) {
            if (! $row instanceof JournalEvent || $row->kind !== 'command') {
                continue;
            }
            [$destination, , $scope] = $this->packet((string) $row->data['binding']);
            $digest = FactualCommandBinding::digest($destinationRef, $message, $scope);
            if ($destination === $destinationRef && $row->data['binding'] === $digest) {
                $lineage = ((object) $message['payload'])->sourceLineage;

                if (! is_string($lineage) || $scope->sellerRef === null || $scope->environment === null || $scope->sourceAuthority === null) {
                    throw new LogicException('Incomplete detached command origin');
                }

                return new SourceAuthorityGrant($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->sourceAuthority, $lineage,
                    $destination, 'simulated-restore-transport', new DateTimeImmutable('+1 hour'), 'sha256:'.$row->data['mac'], $scope->intentOwnerNamespace, $digest);
            }
        }
        throw new LogicException('No original authenticated producer for this packet');
    }
}
