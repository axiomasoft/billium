<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Server\Contracts\Authenticator;
use Billium\Server\Contracts\SellerContextResolver;
use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Data\SourceAuthorityGrant;
use Billium\Server\Tests\Support\MinimalHost;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('pg')]
#[Group('server')]
final class SourceAuthorityIsolationTest extends TestCase
{
    public function test_http_21_requires_an_exact_host_grant_before_custody_and_duplicate_lookup(): void
    {
        MinimalHost::load();
        $database = Database::create();
        try {
            $host = new Host('unused');
            $app = MinimalHost::application($host, $database, 'embedded-commerz', ['platform', 'ledger']);
            foreach ([MerchantCredentials::class, PaymentDrivers::class] as $port) {
                $app->beforeResolving($port, static fn () => self::fail('External observation resolved a provider port'));
            }

            $auth = $this->createStub(Authenticator::class);
            $auth->method('authenticate')->willReturnCallback(static fn (array $headers): AuthenticatedPrincipal => match ($headers['authorization'][0] ?? null) {
                'Bearer x' => new AuthenticatedPrincipal('source-x'),
                'Bearer y' => new AuthenticatedPrincipal('source-y'),
                default => throw new ProblemException(ProblemCode::AuthUnauthenticated),
            });
            $app->instance(Authenticator::class, $auth);
            $grant = static fn (string $source): SourceAuthorityGrant => new SourceAuthorityGrant(
                'payment.observation', $source, Host::INSTALLATION, 'test', Host::SELLER, 'L1', ['shared-partition'],
            );
            $x = [$grant('embedded-commerz')];
            $y = [$grant('remote-billium')];
            $contexts = $this->createStub(SellerContextResolver::class);
            $contexts->method('resolve')->willReturnCallback(static function (AuthenticatedPrincipal $principal, string $seller) use (&$x, $y): ExecutionContext {
                return new ExecutionContext(Host::INSTALLATION, 'test', Host::SELLER, $principal->principalRef,
                    capabilities: ['payment.observation.v1'], sourceAuthorities: $principal->principalRef === 'source-x' ? $x : $y);
            });
            $app->instance(SellerContextResolver::class, $contexts);
            $host->request($app, '/billium/live');
            Host::migrate($app, ['platform', 'ledger']);
            $app->make(Installation::class)->init(Host::INSTALLATION);
            $db = $app->make(DatabaseManager::class)->connection('server_owner');
            $endpoint = '/billium/v1/sellers/'.Host::SELLER.'/observations';

            $asY = ObservationFactory::charge('shared-operation', 1, 3000, fields: [
                'sourceAuthority' => 'remote-billium', 'partitionRef' => 'shared-partition', 'installationRef' => Host::INSTALLATION,
                'sellerRef' => Host::SELLER,
            ]);
            $accepted = $host->request($app, $endpoint, ObservationFactory::json($asY), 'y');
            self::assertSame(ProblemCode::RecoveryRequired, (new Codec)->decodeProblem((string) $accepted->getContent())->code);
            self::assertSame(1, $db->table('billium_inbox_message')->count());

            $used = $host->request($app, $endpoint, ObservationFactory::json($asY), 'x');
            self::assertSame(403, $used->getStatusCode());
            $code = (new Codec)->decodeProblem((string) $used->getContent())->code;
            self::assertSame(ProblemCode::AuthDenied, $code);
            self::assertSame('none', $code->receipt());
            foreach ([
                ['eventId' => '01JBX5Q0Z8M3N4P7R9S2000001'], ['evidenceRef' => 'different-content-same-event'],
                ['sourceAuthority' => 'embedded-commerz', 'sourceLineage' => 'L2'],
                ['sourceAuthority' => 'embedded-commerz', 'partitionRef' => 'unknown-partition'],
                ['sellerRef' => 'seller-b'], ['environment' => 'live'], ['installationRef' => 'other-installation'],
            ] as $change) {
                $response = $host->request($app, $endpoint, ObservationFactory::json(ObservationFactory::with($asY, $change)), 'x');
                self::assertSame(403, $response->getStatusCode());
                self::assertSame($used->getContent(), $response->getContent());
            }

            // A grant with the right authority alone is insufficient, even if the replay already has custody.
            foreach ([
                [],
                [new SourceAuthorityGrant('other-channel', 'remote-billium', Host::INSTALLATION, 'test', Host::SELLER, 'L1', ['shared-partition'])],
                [new SourceAuthorityGrant('payment.observation', 'remote-billium', 'other-installation', 'test', Host::SELLER, 'L1', ['shared-partition'])],
                [new SourceAuthorityGrant('payment.observation', 'remote-billium', Host::INSTALLATION, 'live', Host::SELLER, 'L1', ['shared-partition'])],
                [new SourceAuthorityGrant('payment.observation', 'remote-billium', Host::INSTALLATION, 'test', 'seller-b', 'L1', ['shared-partition'])],
                [new SourceAuthorityGrant('payment.observation', 'remote-billium', Host::INSTALLATION, 'test', Host::SELLER, 'L2', ['shared-partition'])],
                [new SourceAuthorityGrant('payment.observation', 'remote-billium', Host::INSTALLATION, 'test', Host::SELLER, 'L1', [])],
            ] as $invalidGrants) {
                $x = $invalidGrants;
                $response = $host->request($app, $endpoint, ObservationFactory::json($asY), 'x');
                self::assertSame(403, $response->getStatusCode());
                self::assertSame($used->getContent(), $response->getContent());
            }
            self::assertSame(1, $db->table('billium_inbox_message')->count());
            self::assertSame(1, $db->table('billium_feed_partition')->count());

            $x = [$grant('embedded-commerz')];
            $asX = ObservationFactory::with($asY, ['sourceAuthority' => 'embedded-commerz']);
            $accepted = $host->request($app, $endpoint, ObservationFactory::json($asX), 'x');
            self::assertSame(ProblemCode::RecoveryRequired, (new Codec)->decodeProblem((string) $accepted->getContent())->code);
            foreach (['billium_inbox_message' => 'external_event_id', 'billium_feed_partition' => 'partition_ref'] as $table => $column) {
                $value = $column === 'partition_ref' ? $asY->partitionRef : $asY->eventId;
                self::assertSame(['embedded-commerz', 'remote-billium'], $db->table($table)->where($column, $value)
                    ->orderBy('source_authority')->pluck('source_authority')->all());
            }
            foreach (['billium_inbox_conflict', 'billium_observed_payment', 'billium_payment', 'billium_allocation_receipt', 'billium_outbox_event'] as $table) {
                self::assertSame(0, $db->table($table)->count(), $table);
            }
            self::assertFalse($db->getSchemaBuilder()->hasTable('billium_execution_operation'));
        } finally {
            Database::drop($database);
        }
    }
}
