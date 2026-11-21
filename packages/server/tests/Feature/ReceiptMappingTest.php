<?php

declare(strict_types=1);

namespace Billium\Server\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\ReceiptResult;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Contracts\Idempotency\ReceiptAbsenceAuthority;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Enums\Verdict;
use Billium\Server\Internal\Http\ProblemResponse;
use Billium\Server\Tests\Support\MinimalHost;
use BilliumMinimalHost\Database;
use BilliumMinimalHost\Host;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;

#[Group('pg')]
#[Group('server')]
final class ReceiptMappingTest extends TestCase
{
    public function test_problem_status_and_receipt_policy_agree_with_the_contract(): void
    {
        $manifest = json_decode((string) file_get_contents(MinimalHost::root().'/spec/server/openapi.v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $codec = new Codec;
        foreach (ProblemCode::cases() as $code) {
            $response = ProblemResponse::from($code);
            $body = $codec->decodeProblem((string) $response->getContent());
            self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
            self::assertSame($manifest['x-billium-problem-http'][$code->value], $response->getStatusCode());
            self::assertSame($response->getStatusCode(), $body->status);
            self::assertSame($code, $body->code);
        }
    }

    public function test_receipt_lookup_requires_positive_evidence_of_absence_and_writes_nothing(): void
    {
        MinimalHost::load();
        $database = Database::create();
        try {
            $host = new Host('receipt-test');
            $app = MinimalHost::application($host, $database, 'ledger-only', ['platform', 'ledger']);
            $app->instance(AuthorizationVerdict::class, $this->createConfiguredStub(AuthorizationVerdict::class, ['authorize' => Verdict::Allow]));
            $host->request($app, '/billium/live');
            Host::migrate($app, ['platform', 'ledger']);
            $db = $app->make(DatabaseManager::class)->connection('server_owner');
            $path = '/billium/v1/sellers/'.Host::SELLER.'/receipts/find';
            $query = json_encode(['schema' => 'receipt.find.v1', 'installationRef' => Host::INSTALLATION, 'environment' => 'test',
                'sellerRef' => Host::SELLER, 'receiptRef' => 'missing-receipt'], JSON_THROW_ON_ERROR);

            $codec = new Codec;
            $unknown = $host->request($app, $path, $query);
            self::assertSame(503, $unknown->getStatusCode());
            self::assertSame(ProblemCode::LookupUnavailable, $codec->decodeProblem((string) $unknown->getContent())->code);
            $certificate = (object) ['scopeProof' => 'fixture-current-lineage', 'coveredThrough' => '2026-11-21T00:00:00Z'];
            $authority = $this->createMock(ReceiptAbsenceAuthority::class);
            $authority->expects(self::exactly(2))->method('confirmAbsent')->willReturnCallback(
                static function (Caller $caller, FinancialScope $scope, string $ref) use ($certificate): stdClass {
                    self::assertSame(Host::SELLER, $caller->sellerRef);
                    self::assertEquals(new FinancialScope(Host::INSTALLATION, Host::SELLER, 'test', null), $scope);
                    self::assertSame('missing-receipt', $ref);

                    return $certificate;
                },
            );
            $app->instance(ReceiptAbsenceAuthority::class, $authority);
            $certified = $host->request($app, $path, $query);
            self::assertSame(200, $certified->getStatusCode());
            $result = $codec->decode((string) $certified->getContent());
            self::assertInstanceOf(ReceiptResult::class, $result);
            self::assertSame('absent', $result->status);
            self::assertEquals($certificate, $result->absenceCertificate);
            self::assertNull($result->body);
            self::assertSame($result->digest, $codec->digestOf($result));
            self::assertSame($certified->getContent(), $host->request($app, $path, $query)->getContent());
            self::assertSame(0, $db->table('billium_idempotency_receipt')->count());
            self::assertSame(0, $db->table('billium_outbox_event')->count());
        } finally {
            Database::drop($database);
        }
    }
}
