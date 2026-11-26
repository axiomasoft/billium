<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\ScopedCallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\SourceAuthorityGrant;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Internal\Runtime\PaymentObservationCommandReceiver;
use Billium\Ledger\Contracts\ObservationApplier;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PaymentObservationCommandReceiverTest extends TestCase
{
    public function test_invalid_payload_digest_never_authenticates_or_calls_ledger(): void
    {
        $message = $this->message();
        $message['payloadDigest'] = 'sha256:'.str_repeat('0', 64);
        $auth = $this->createMock(IncomingCommandAuthenticator::class);
        $auth->expects(self::never())->method('authenticate');
        $ledger = $this->createMock(ObservationApplier::class);
        $ledger->expects(self::never())->method('apply');
        $receiver = $this->receiver($auth, $ledger, $this->callers());

        $this->expectException(ProblemException::class);
        $receiver->receive(PaymentObservationCommandReceiver::DESTINATION, $message);
    }

    public function test_expired_and_foreign_grants_never_call_ledger(): void
    {
        $message = $this->message();
        foreach (['expired', 'seller', 'lineage', 'audience'] as $mismatch) {
            $grant = $this->grant($message, $mismatch);
            $auth = $this->createMock(IncomingCommandAuthenticator::class);
            $auth->expects(self::once())->method('authenticate')->willReturn($grant);
            $ledger = $this->createMock(ObservationApplier::class);
            $ledger->expects(self::never())->method('apply');
            $receiver = $this->receiver($auth, $ledger, $this->callers());
            try {
                $receiver->receive(PaymentObservationCommandReceiver::DESTINATION, $message);
                self::fail('Foreign or expired source grant was admitted');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::AuthDenied, $error->problem);
            }
        }
    }

    public function test_receiving_failure_restores_prior_caller_and_propagates_for_retry(): void
    {
        $message = $this->message();
        $grant = $this->grant($message);
        $auth = $this->createMock(IncomingCommandAuthenticator::class);
        $auth->expects(self::once())->method('authenticate')->willReturn($grant);
        $callers = $this->callers();
        $prior = $callers->current();
        $ledger = $this->createMock(ObservationApplier::class);
        $ledger->expects(self::once())->method('apply')->willReturnCallback(function (string $json) use ($callers, $grant): never {
            self::assertSame($grant->sellerRef, $callers->current()->sellerRef);
            self::assertSame([$grant->sourceAuthority], $callers->current()->sourceAuthorities);
            self::assertInstanceOf(PaymentObservation::class, (new Codec)->decode($json));
            throw new RuntimeException('custody-not-confirmed');
        });
        try {
            $this->receiver($auth, $ledger, $callers)->receive(PaymentObservationCommandReceiver::DESTINATION, $message);
            self::fail('Unconfirmed custody was acknowledged');
        } catch (RuntimeException $error) {
            self::assertSame('custody-not-confirmed', $error->getMessage());
        }
        self::assertSame($prior, $callers->current());
    }

    /** @return array<string, mixed> */
    private function message(): array
    {
        $text = file_get_contents(dirname(__DIR__, 4).'/fixtures/vectors/payment/observation/charge-valid.json');
        self::assertIsString($text);
        $vector = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($vector);
        $script = $vector['script'];
        self::assertIsArray($script);
        $step = $script[0];
        self::assertIsArray($step);
        $args = $step['args'];
        self::assertIsArray($args);
        $json = $args['json_text'];
        self::assertIsString($json);
        $codec = new Codec;
        $observation = $codec->decode($json);
        self::assertInstanceOf(PaymentObservation::class, $observation);

        return ['schema' => 'billium.command.v1', 'commandRef' => 'observation-command-1',
            'businessRef' => $observation->operationRef, 'commandType' => PaymentObservation::SCHEMA,
            'payloadDigest' => 'sha256:'.hash('sha256', $codec->encode($observation)), 'payload' => $observation->raw];
    }

    /** @param array<string, mixed> $message */
    private function grant(array $message, string $mismatch = ''): SourceAuthorityGrant
    {
        $codec = new Codec;
        $payload = $codec->decode($codec->canonicalize($message['payload']));
        self::assertInstanceOf(PaymentObservation::class, $payload);

        return new SourceAuthorityGrant($payload->installationRef, $mismatch === 'seller' ? 'foreign' : $payload->sellerRef,
            $payload->environment, $payload->sourceAuthority, $mismatch === 'lineage' ? 'foreign' : $payload->sourceLineage,
            $mismatch === 'audience' ? 'foreign' : PaymentObservationCommandReceiver::DESTINATION,
            'authenticated-execution-owner', new DateTimeImmutable($mismatch === 'expired' ? '-1 minute' : '+1 minute'),
            'sha256:'.str_repeat('a', 64));
    }

    private function receiver(IncomingCommandAuthenticator $auth, ObservationApplier $ledger, ScopedCallerContext $callers): PaymentObservationCommandReceiver
    {
        $databases = self::createStub(DatabaseManager::class);
        $databases->method('getConnections')->willReturn([]);

        return new PaymentObservationCommandReceiver(new Codec, $auth, $callers, $ledger, $databases, ModuleCatalog::packaged());
    }

    private function callers(): ScopedCallerContext
    {
        return new class implements ScopedCallerContext
        {
            private Caller $caller;

            public function __construct()
            {
                $this->caller = new Caller('prior-seller', 'test', 'prior-installation', 'prior-actor');
            }

            public function current(): Caller
            {
                return $this->caller;
            }

            /**
             * @template T
             *
             * @param  Closure(): T  $body
             * @return T
             */
            public function run(Caller $caller, Closure $body): mixed
            {
                $previous = $this->caller;
                $this->caller = $caller;
                try {
                    return $body();
                } finally {
                    $this->caller = $previous;
                }
            }
        };
    }
}
