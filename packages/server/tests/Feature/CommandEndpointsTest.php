<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\CancelOperation;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Envelopes\PaymentAdmission;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Enums\Verdict;
use Billium\Server\Tests\Support\MinimalHost;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

#[Group('pg')]
#[Group('server')]
final class CommandEndpointsTest extends TestCase
{
    private const string ROOT = '/billium/v1/sellers/seller-acme';

    public function test_lost_admission_response_retries_the_original_command_and_keeps_its_receipt(): void
    {
        MinimalHost::load();
        $database = Database::create();
        try {
            $host = new Host('command-test');
            $modules = ['platform', 'ledger', 'execution'];
            $app = MinimalHost::application($host, $database, 'remote-billium', $modules);
            foreach ([AdapterQualification::class, DispatchEgress::class, ExecutionOriginResolver::class, ExecutionReadiness::class,
                MerchantCredentials::class, PaymentDrivers::class, ProviderAbsenceVerifier::class, WriterAdmission::class] as $port) {
                $app->instance($port, $this->createStub($port));
            }
            $app->instance(AuthorizationVerdict::class, $this->createConfiguredStub(AuthorizationVerdict::class, ['authorize' => Verdict::Allow]));
            $host->request($app, '/billium/live');
            Host::migrate($app, $modules);

            $codec = new Codec;
            $key = '7e0c4a52-91d3-4b6f-8a25-c3d1e9f04b87';
            $body = ['schema' => ExecutePayment::SCHEMA, 'commandKey' => $key, 'operationRef' => 'fixture-op', 'operationKind' => 'charge',
                'source' => ['context' => 'fixture', 'orderRef' => 'order', 'intentRef' => 'intent'], 'installationRef' => Host::INSTALLATION,
                'environment' => 'test', 'sellerRef' => Host::SELLER, 'executionProfile' => 'remote-billium', 'routingEpoch' => 1,
                'merchantAccountRef' => 'merchant', 'money' => ['amount' => '10.00', 'currency' => 'RUB'],
                'bindingRevision' => 'binding', 'requestedAt' => '2026-10-17T12:00:00Z'];
            $digest = $codec->digest($body);
            $wire = json_encode([...$body, 'digest' => $digest], JSON_THROW_ON_ERROR);
            $stored = StoredOutcome::accepted(new ExecutionReceipt('receipt-1', $key, 'fixture-op', $digest, 'admitted', false, [], 0, 'fixture-op'));
            $attempts = 0;
            $commands = $this->createMock(RootPaymentCommands::class);
            $commands->expects(self::exactly(3))->method('admit')->willReturnCallback(
                static function (ExecutionScope $scope, ExecutePayment $command) use ($codec, $key, $digest, $stored, &$attempts): StoredOutcome {
                    self::assertSame(Host::SELLER, $scope->sellerRef);
                    self::assertSame($key, $command->commandKey);
                    self::assertSame('2026-10-17T12:00:00Z', $command->requestedAt);
                    self::assertSame($digest, $codec->digestOf($command));
                    if (++$attempts === 1) {
                        throw new ProblemException(ProblemCode::TransportUnavailable);
                    }

                    return $stored->asReplay();
                },
            );
            $commands->expects(self::once())->method('cancel')->willReturnCallback(
                static fn (ExecutionScope $scope, CancelOperation $command): StoredOutcome => StoredOutcome::accepted(
                    new ExecutionReceipt('receipt-cancel', $command->commandKey, $command->operationRef,
                        $codec->digestOf($command), 'cancelled', false, [], 1, $command->operationRef),
                ),
            );
            $app->instance(RootPaymentCommands::class, $commands);

            $path = self::ROOT.'/payments/execute';
            foreach ([
                [json_encode([...$body, 'digest' => 'sha256:'.str_repeat('0', 64)], JSON_THROW_ON_ERROR), $key, 'application/json'],
                [json_encode([...$body, 'unexpected' => true], JSON_THROW_ON_ERROR), $key, 'application/json'],
                [$wire, 'incorrect-key', 'application/json'],
                [$wire, $key, 'text/plain'],
            ] as [$invalidBody, $header, $contentType]) {
                $response = $this->post($app, $path, $invalidBody, $header, $contentType);
                self::assertSame(400, $response->getStatusCode());
                self::assertSame('none', $codec->decodeProblem((string) $response->getContent())->code->receipt());
            }
            self::assertSame(400, $this->post($app, self::ROOT.'/payments/authorize', $wire, $key)->getStatusCode());
            $cancel = json_encode([
                'schema' => 'payment.cancel.v1', 'installationRef' => Host::INSTALLATION, 'environment' => 'test',
                'sellerRef' => Host::SELLER, 'operationRef' => 'fixture-op', 'commandKey' => $key,
            ], JSON_THROW_ON_ERROR);
            self::assertSame(403, $host->request($app, self::ROOT.'/operations/other/cancel', $cancel)->getStatusCode());
            self::assertSame(0, $app->make(DatabaseManager::class)->connection('server_owner')->table('billium_idempotency_receipt')->count());

            $unknown = $this->post($app, $path, $wire, $key);
            self::assertSame(503, $unknown->getStatusCode());
            self::assertTrue($codec->decodeProblem((string) $unknown->getContent())->code->sameKey());
            $accepted = $this->post($app, $path, $wire, $key);
            self::assertSame(202, $accepted->getStatusCode());
            $receipt = $codec->decode((string) $accepted->getContent());
            self::assertInstanceOf(PaymentAdmission::class, $receipt);
            self::assertSame('fixture-op', $receipt->outcomeQueryRef);
            $repeat = $this->post($app, $path, $wire, $key);
            self::assertSame(202, $repeat->getStatusCode());
            self::assertSame($accepted->getContent(), $repeat->getContent());
            $cancelled = $host->request($app, self::ROOT.'/operations/fixture-op/cancel', $cancel);
            self::assertSame(200, $cancelled->getStatusCode());
            $terminal = $codec->decode((string) $cancelled->getContent());
            self::assertInstanceOf(PaymentAdmission::class, $terminal);
            self::assertSame('known-terminal', $terminal->result);
        } finally {
            Database::drop($database);
        }
    }

    private function post(Application $app, string $path, string $body, string $key, string $contentType = 'application/json'): Response
    {
        $request = Request::create($path, 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer command-test',
            'CONTENT_TYPE' => $contentType, 'HTTP_IDEMPOTENCY_KEY' => $key], content: $body);
        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }
}
