<?php

declare(strict_types=1);

namespace Billium\Conformance\ConsumerBridge;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Data\Accounting\AccountingFactCategory;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\FinBridgeExportEnvelope;
use Billium\Testkit\Accounting\FakeFinBridgeConsumer;
use Billium\Testkit\Coordinator\FakeProductCoordinator;
use Billium\Testkit\Fixtures\TransportFaultAdapter;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Reference product-agnostic external consumer/bridge adapter speaking billium.consumer.bridge.protocol.v1.
 *
 * Operates outside Billium internals over HTTP + public Billium Contracts, persists committed contiguous
 * feed cursors only after snapshot/item durability, and never writes external host tables into Billium.
 */
final class BridgeReferenceAdapter
{
    private const array SUPPORTED_OPS = [
        'http_command', 'replay_prepared', 'preflight_command', 'feed_bootstrap_and_ack',
        'feed_tail_sync', 'accounting_consume_events', 'host_provisioning_drill', 'archive_decode_matrix',
    ];

    private string $producerUrl = '';

    private string $boundary = 'loopback';

    private string $workDir = '';

    /** @var array<string, array{bytes: string, digest: string, schema: string, commandKey: ?string, operationRef: ?string, routingEpoch: ?int, path: string}> */
    private array $prepared = [];

    private readonly TransportFaultAdapter $transportAdapter;

    private readonly FakeFinBridgeConsumer $accountingConsumer;

    private readonly FakeProductCoordinator $coordinator;

    public function __construct(private readonly string $repoRoot)
    {
        $this->transportAdapter = new TransportFaultAdapter(new Codec);
        $this->accountingConsumer = new FakeFinBridgeConsumer('bridge:reference-accounting-consumer');
        $this->coordinator = new FakeProductCoordinator;
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    public function handle(array $message): array
    {
        $type = (string) ($message['type'] ?? '');
        if ($type === 'start') {
            $this->producerUrl = rtrim((string) $message['producerUrl'], '/');
            $this->boundary = (string) $message['boundary'];
            if ($this->boundary === 'external-runtime') {
                throw new RuntimeException('Bundled reference adapter cannot satisfy external-runtime boundary; attach an independent external consumer');
            }
            $this->workDir = (string) ($message['workDir'] ?? sys_get_temp_dir());

            return [
                'type' => 'ready', 'protocol' => 'billium.consumer.bridge.protocol.v1',
                'adapter' => 'billium-reference-bridge-consumer', 'adapterVersion' => '1.0.0',
                'commitHash' => $this->gitCommit(), 'packageHash' => $this->packageHash(),
                'specRevision' => '2026-10',
                'specHash' => 'sha256:'.hash_file('sha256', $this->repoRoot.'/spec/revisions/2026-10/revision.lock.json'),
                'deliveryProfile' => $this->boundary === 'double' ? 'double-transport' : 'loopback-http',
                'isBundledReference' => true,
                'supportedOps' => self::SUPPORTED_OPS, 'paidPspEffect' => false,
            ];
        }

        if ($type !== 'step') {
            throw new RuntimeException("Unsupported message type: {$type}");
        }

        $caseId = (string) $message['caseId'];
        $step = (string) $message['step'];
        $op = (string) $message['op'];
        /** @var array<string, mixed> $args */
        $args = is_array($message['args'] ?? null) ? $message['args'] : [];

        $observed = match ($op) {
            'http_command' => $this->httpCommand($step, $args),
            'replay_prepared' => $this->replayPrepared($args),
            'preflight_command' => $this->preflightCommand($args),
            'feed_bootstrap_and_ack' => $this->feedBootstrapAndAck($args),
            'feed_tail_sync' => $this->feedTailSync($args),
            'accounting_consume_events' => $this->accountingConsumeEvents($args),
            'host_provisioning_drill' => $this->hostProvisioningDrill($args),
            'archive_decode_matrix' => $this->archiveDecodeMatrix(),
            default => throw new RuntimeException("Unsupported consumer op: {$op}"),
        };

        return [
            'type' => 'result',
            'caseId' => $caseId,
            'step' => $step,
            'observed' => $observed,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function httpCommand(string $stepName, array $args): array
    {
        $path = (string) $args['path'];
        $method = isset($args['body']) ? 'POST' : 'GET';
        $actor = (string) ($args['actor'] ?? 'owner');
        $fault = (string) ($args['fault'] ?? 'forward');
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$actor,
        ];
        if (isset($args['delivery'])) {
            $headers['X-Fixture-Delivery'] = (string) $args['delivery'];
        }

        if ($method === 'GET') {
            $raw = $this->rawHttp('GET', $path, '', $headers);

            return $this->parseResponse($raw);
        }

        $body = $args['body'];
        $seal = is_string($args['seal'] ?? null) ? $args['seal'] : null;
        $rawSend = (bool) ($args['rawSend'] ?? false);

        if ($rawSend) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (is_array($body) && is_string($body['commandKey'] ?? null)) {
                $headers['Idempotency-Key'] = $body['commandKey'];
            }
            $raw = $this->rawHttp('POST', $path, $json, $headers);

            return $this->parseResponse($raw);
        }

        $prepared = $this->transportAdapter->prepareCommand(
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $path,
            $seal,
        );
        $saveAs = is_string($args['saveAs'] ?? null) ? $args['saveAs'] : $stepName;
        $this->prepared[$saveAs] = $prepared;

        $dispatched = $this->transportAdapter->dispatchWithFault(
            $prepared,
            fn (string $p, string $bytes, array $h): array => $this->rawHttp('POST', $p, $bytes, $h),
            $fault,
            $headers,
        );

        if ($dispatched['status'] === null || $dispatched['body'] === null) {
            return [
                'outcome' => $dispatched['outcome'],
                'upstreamCommitted' => $dispatched['upstreamCommitted'],
                'sameBytes' => $dispatched['sameBytes'],
                'newKeyMinted' => $dispatched['newKeyMinted'],
                'cancelInvoked' => $dispatched['cancelInvoked'],
                'preparedDigest' => $prepared['digest'],
            ];
        }

        return array_replace($this->parseResponse([
            'status' => $dispatched['status'],
            'headers' => ['content-type' => 'application/json'],
            'body' => $dispatched['body'],
        ]), [
            'outcome' => $dispatched['outcome'],
            'sameBytes' => $dispatched['sameBytes'],
            'newKeyMinted' => $dispatched['newKeyMinted'],
            'cancelInvoked' => $dispatched['cancelInvoked'],
            'preparedDigest' => $prepared['digest'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function replayPrepared(array $args): array
    {
        $ref = (string) $args['use'];
        $prepared = $this->prepared[$ref] ?? throw new RuntimeException("Unknown prepared command {$ref}");
        $actor = (string) ($args['actor'] ?? 'owner');
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$actor,
        ];
        if ($prepared['commandKey'] !== null) {
            $headers['Idempotency-Key'] = $prepared['commandKey'];
        }
        if (isset($args['delivery'])) {
            $headers['X-Fixture-Delivery'] = (string) $args['delivery'];
        }
        $raw = $this->rawHttp('POST', $prepared['path'], $prepared['bytes'], $headers);

        return array_replace($this->parseResponse($raw), [
            'sameBytes' => true,
            'newKeyMinted' => false,
            'cancelInvoked' => false,
            'preparedDigest' => $prepared['digest'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function preflightCommand(array $args): array
    {
        $path = (string) ($args['path'] ?? '/billium/v1/sellers/seller-a/payments/execute');
        $json = json_encode($args['body'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try {
            $prepared = $this->transportAdapter->prepareCommand($json, $path);

            return ['accepted' => true, 'wireAttempts' => 0, 'schema' => $prepared['schema'], 'code' => null];
        } catch (ProblemException $error) {
            return ['accepted' => false, 'wireAttempts' => 0, 'code' => $error->problem->value];
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function feedBootstrapAndAck(array $args): array
    {
        $seller = (string) ($args['seller'] ?? BridgeHost::SELLER);
        $eventTypes = is_array($args['eventTypes'] ?? null) ? array_values($args['eventTypes']) : ['obligation.created.v1', 'allocation.applied.v1'];
        $stateKey = (string) ($args['stateKey'] ?? 'default');
        $acknowledge = (bool) ($args['acknowledge'] ?? true);

        $openRes = $this->httpCommand('feed-open-'.$stateKey, [
            'path' => "/billium/v1/sellers/{$seller}/feed/open",
            'body' => [
                'schema' => 'billium.feed.open.v1',
                'commandKey' => (string) ($args['openKey'] ?? '00000000-0000-4100-8800-000000009001'),
                'installationRef' => BridgeHost::INSTALLATION,
                'environment' => 'test',
                'sellerRef' => $seller,
                'scope' => [
                    'installationRef' => BridgeHost::INSTALLATION,
                    'environment' => 'test',
                    'sellerRef' => $seller,
                    'channel' => 'events',
                    'sourceAuthority' => 'billium',
                    'lineage' => '1',
                ],
                'eventContractRevision' => 'events-2026-10-v1',
                'envelopeSchemas' => ['billium.event.v1'],
                'eventTypes' => $eventTypes,
            ],
        ]);
        $selection = $openRes['body'];
        $subscriptionRef = (string) $selection['subscriptionRef'];
        $selectionDigest = (string) $selection['selectionDigest'];

        if (! $acknowledge) {
            $this->saveCursorState($stateKey, [
                'subscriptionRef' => $subscriptionRef,
                'selectionDigest' => $selectionDigest,
                'committedCursor' => (string) $selection['nextCursor'],
                'appliedItems' => [],
            ]);

            return [
                'subscriptionRef' => $subscriptionRef,
                'selectionDigest' => $selectionDigest,
                'committedCursor' => (string) $selection['nextCursor'],
                'acknowledged' => false,
            ];
        }

        $bootRes = $this->httpCommand('feed-boot-'.$stateKey, [
            'path' => "/billium/v1/sellers/{$seller}/feed/bootstrap",
            'body' => [
                'schema' => 'billium.feed.bootstrap_request.v1',
                'commandKey' => (string) ($args['bootstrapKey'] ?? '00000000-0000-4100-8800-000000009002'),
                'installationRef' => BridgeHost::INSTALLATION,
                'environment' => 'test',
                'sellerRef' => $seller,
                'subscriptionRef' => $subscriptionRef,
                'selectionDigest' => $selectionDigest,
            ],
        ]);
        $boot = $bootRes['body'];
        $applied = [];
        foreach (($boot['snapshots'] ?? []) as $sourceKey => $records) {
            if (is_array($records)) {
                foreach ($records as $idx => $item) {
                    $id = is_array($item) ? (string) ($item['obligationRef'] ?? $item['receiptRef'] ?? $item['paymentRef'] ?? ($sourceKey.':'.$idx)) : ($sourceKey.':'.$idx);
                    $applied['snapshot:'.$sourceKey.':'.$id] = $item;
                }
            }
        }
        // Persist durable dispositions and cursor atomically BEFORE acknowledging bootstrap lease.
        $this->saveCursorState($stateKey, [
            'subscriptionRef' => $subscriptionRef,
            'selectionDigest' => $selectionDigest,
            'committedCursor' => (string) $boot['nextCursor'],
            'snapshotDigest' => (string) $boot['snapshotDigest'],
            'appliedItems' => $applied,
        ]);

        $ackRes = $this->httpCommand('feed-ack-'.$stateKey, [
            'path' => "/billium/v1/sellers/{$seller}/feed/bootstrap/ack",
            'body' => [
                'schema' => 'billium.feed.bootstrap_ack.v1',
                'installationRef' => BridgeHost::INSTALLATION,
                'environment' => 'test',
                'sellerRef' => $seller,
                'subscriptionRef' => $subscriptionRef,
                'selectionDigest' => $selectionDigest,
                'leaseRef' => (string) $boot['leaseRef'],
                'snapshotDigest' => (string) $boot['snapshotDigest'],
            ],
        ]);

        return [
            'ackStatus' => $ackRes['status'],
            'subscriptionRef' => $subscriptionRef,
            'selectionDigest' => $selectionDigest,
            'snapshotDigest' => (string) $boot['snapshotDigest'],
            'committedCursor' => (string) $boot['nextCursor'],
            'snapshotItemCount' => count($applied),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function feedTailSync(array $args): array
    {
        $seller = (string) ($args['seller'] ?? BridgeHost::SELLER);
        $stateKey = (string) ($args['stateKey'] ?? 'default');
        $crashBeforeCommit = (bool) ($args['crashBeforeCursorCommit'] ?? false);
        $state = $this->loadCursorState($stateKey);

        $readRes = $this->httpCommand('feed-read-'.$stateKey.'-'.bin2hex(random_bytes(3)), [
            'path' => "/billium/v1/sellers/{$seller}/feed/read",
            'body' => [
                'schema' => 'billium.feed.read.v1',
                'installationRef' => BridgeHost::INSTALLATION,
                'environment' => 'test',
                'sellerRef' => $seller,
                'subscriptionRef' => $state['subscriptionRef'],
                'selectionDigest' => $state['selectionDigest'],
                'cursor' => $state['committedCursor'],
                'limit' => (int) ($args['limit'] ?? 50),
            ],
            'rawSend' => true,
        ]);
        if ($readRes['status'] >= 400) {
            return [
                'status' => $readRes['status'],
                'code' => $readRes['body']['code'] ?? null,
                'cursorUnchanged' => true,
            ];
        }

        $events = is_array($readRes['body']['events'] ?? null) ? $readRes['body']['events'] : [];
        $applied = is_array($state['appliedItems'] ?? null) ? $state['appliedItems'] : [];
        $newlyApplied = 0;
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $economicKey = 'event:'.($event['type'] ?? '').':'.($event['aggregateRef'] ?? '').':'.($event['aggregateRevision'] ?? '');
            if (! isset($applied[$economicKey])) {
                $applied[$economicKey] = $event['digest'] ?? true;
                $newlyApplied++;
            }
        }
        $nextCursor = (string) $readRes['body']['nextCursor'];
        $cursorAdvanced = $nextCursor !== $state['committedCursor'];

        if ($crashBeforeCommit) {
            // Item dispositions persisted idempotently, but cursor NOT yet advanced — replay will re-read same tail.
            $state['appliedItems'] = $applied;
            $this->saveCursorState($stateKey, $state);

            return [
                'status' => 200,
                'eventsRead' => count($events),
                'newlyApplied' => $newlyApplied,
                'totalUniqueApplied' => count($applied),
                'cursorCommitted' => false,
            ];
        }

        $state['appliedItems'] = $applied;
        $state['committedCursor'] = $nextCursor;
        $this->saveCursorState($stateKey, $state);

        return [
            'status' => 200,
            'eventsRead' => count($events),
            'newlyApplied' => $newlyApplied,
            'totalUniqueApplied' => count($applied),
            'cursorCommitted' => true,
            'cursorAdvanced' => $cursorAdvanced,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function accountingConsumeEvents(array $args): array
    {
        $deliveries = (int) ($args['duplicateDeliveries'] ?? 3);
        /** @var array<string, mixed> $raw */
        $raw = is_array($args['envelope'] ?? null) ? $args['envelope'] : throw new RuntimeException('Missing FinBridgeExportEnvelope payload');
        $envelope = new FinBridgeExportEnvelope(
            schemaVersion: (string) $raw['schemaVersion'],
            sourceFactRef: (string) $raw['sourceFactRef'],
            factKind: AccountingFactKind::from((string) $raw['factKind']),
            category: AccountingFactCategory::from((string) $raw['category']),
            installationRef: (string) $raw['installationRef'],
            sellerRef: (string) $raw['sellerRef'],
            environment: (string) $raw['environment'],
            sourceLineage: (string) $raw['sourceLineage'],
            currency: (string) $raw['currency'],
            amountMinor: (int) $raw['amountMinor'],
            amountDecimal: (string) $raw['amountDecimal'],
            occurredAt: new DateTimeImmutable((string) $raw['occurredAt']),
            snapshotDigest: (string) $raw['snapshotDigest'],
            exportDigest: (string) $raw['exportDigest'],
            originalFactRef: is_string($raw['originalFactRef'] ?? null) ? $raw['originalFactRef'] : null,
            linkedCashFactRefs: is_array($raw['linkedCashFactRefs'] ?? null) ? array_values($raw['linkedCashFactRefs']) : [],
            exceptional: (bool) ($raw['exceptional'] ?? false),
            attributes: is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [],
        );
        $receiptRefs = [];
        for ($i = 1; $i <= $deliveries; $i++) {
            $this->accountingConsumer->acknowledgeDelivery($envelope, 'delivery-attempt-'.$i);
            $receipt = $this->accountingConsumer->apply($envelope);
            $receiptRefs[$receipt->receiptRef] = true;
        }

        return [
            'sourceFactRefStable' => $this->accountingConsumer->receiptFor($envelope->sourceFactRef)?->sourceFactRef === $envelope->sourceFactRef,
            'uniqueAppliedReceipts' => count($receiptRefs),
            'deliveryAckCount' => $this->accountingConsumer->deliveryAckCount($envelope->sourceFactRef),
            'appliedCount' => $this->accountingConsumer->appliedCount(AccountingFactCategory::CashIn, AccountingFactKind::PaymentCaptured),
            'totalPostedMinor' => $this->accountingConsumer->totalMinorByCategory(AccountingFactCategory::CashIn, 'RUB'),
            'glWriteCount' => $this->accountingConsumer->glWriteCount(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function hostProvisioningDrill(array $args): array
    {
        $paymentRef = (string) ($args['paymentRef'] ?? 'ct17-op-1');
        $grantKey = (string) ($args['grantKey'] ?? 'ct17-grant-1');

        $this->coordinator->setProvisioningOffline(true);
        $first = $this->coordinator->provisionAfterSettledPayment($paymentRef, 'component-main', $grantKey, true);

        $this->coordinator->setProvisioningOffline(false);
        $second = $this->coordinator->provisionAfterSettledPayment($paymentRef, 'component-main', $grantKey, true);
        $idempotent = $this->coordinator->provisionAfterSettledPayment($paymentRef, 'component-main', $grantKey, true);

        return [
            'firstSucceeded' => $first->succeeded,
            'firstRetryProvisionOnly' => $first->retryProvisionOnly,
            'firstChargeTriggered' => $first->chargeTriggered,
            'secondSucceeded' => $second->succeeded,
            'secondChargeTriggered' => $second->chargeTriggered,
            'idempotentReceiptRef' => $idempotent->receiptRef === $second->receiptRef,
            'extraChargesTriggered' => $this->coordinator->extraChargesFromProvisionRetry() + $this->coordinator->backupChargesInitiated(),
            'activeGrant' => $this->coordinator->hasActiveGrant($grantKey),
        ];
    }

    /** @return array<string, mixed> */
    private function archiveDecodeMatrix(): array
    {
        $codec = new Codec;
        $envelope = [
            'schema' => 'ledger.payment_status.v1',
            'installationRef' => BridgeHost::INSTALLATION,
            'environment' => 'test',
            'sellerRef' => BridgeHost::SELLER,
            'result' => [
                'paymentRef' => 'ct15-pay-1',
                'sourceAuthority' => 'standalone-billium',
                'providerAccountRef' => 'pa-ext-1',
                'billingAccountRef' => 'billing-main',
                'applied' => [
                    'revision' => 1,
                    'state' => 'succeeded',
                    'requested' => ['amount' => '10.00', 'currency' => 'RUB'],
                    'authorizedTotal' => ['amount' => '10.00', 'currency' => 'RUB'],
                    'capturedTotal' => ['amount' => '10.00', 'currency' => 'RUB'],
                    'refundedTotal' => ['amount' => '0.00', 'currency' => 'RUB'],
                    'allocated' => ['amount' => '10.00', 'currency' => 'RUB'],
                    'unallocated' => ['amount' => '0.00', 'currency' => 'RUB'],
                ],
                'observed' => [
                    'revision' => 1,
                    'authorizedTotal' => ['amount' => '10.00', 'currency' => 'RUB'],
                    'capturedTotal' => ['amount' => '10.00', 'currency' => 'RUB'],
                    'refundedTotal' => ['amount' => '0.00', 'currency' => 'RUB'],
                    'voidedTotal' => ['amount' => '0.00', 'currency' => 'RUB'],
                    'evidenceRefs' => ['ev-ct15-1'],
                    'observedAt' => '2026-11-22T10:00:00Z',
                ],
                'financialHold' => ['active' => false, 'reasonCodes' => [], 'discrepancyRefs' => []],
                'exposure' => [
                    'currency' => 'RUB',
                    'overCaptured' => ['amount' => '0.00', 'currency' => 'RUB'],
                    'overRefunded' => ['amount' => '0.00', 'currency' => 'RUB'],
                ],
                'spendable' => ['amount' => '0.00', 'currency' => 'RUB'],
                'projectionStatus' => 'current',
                'bootstrap' => ['status' => 'not_started'],
                'reconciliation' => ['status' => 'consistent'],
            ],
            'bridgeAdditiveExtension' => ['traceSchema' => 'ext.v2', 'hop' => 1],
        ];
        $envelope['digest'] = $codec->digest($envelope, 'digest');
        $wireJson = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tampered = $envelope;
        $tampered['bridgeAdditiveExtension'] = ['traceSchema' => 'ext.v2', 'hop' => 999];
        $tamperedJson = json_encode($tampered, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $currentCheck = $this->transportAdapter->verifyFullEnvelopeDigest($wireJson);
        $tamperedCheck = $this->transportAdapter->verifyFullEnvelopeDigest($tamperedJson);

        $sharedFind = [
            'schema' => 'payment.find.v1',
            'operationRef' => 'ct15-find-1',
            'status' => 'succeeded',
            'revision' => 1,
            'capturedTotal' => ['amount' => '10.00', 'currency' => 'RUB'],
            'refundedTotal' => ['amount' => '0.00', 'currency' => 'RUB'],
            'reservedRefundTotal' => ['amount' => '0.00', 'currency' => 'RUB'],
            'bridgeAdditiveExtension' => ['traceSchema' => 'ext.v2', 'hop' => 1],
        ];
        $sharedFind['digest'] = $codec->digest($sharedFind, 'digest');
        $sharedFindJson = json_encode($sharedFind, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tamperedFind = $sharedFind;
        $tamperedFind['bridgeAdditiveExtension'] = ['traceSchema' => 'ext.v2', 'hop' => 999];
        $tamperedFindJson = json_encode($tamperedFind, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Verify pinned N-1 archive from conformance/compatibility/matrix.json via isolated client process.
        $matrix = json_decode((string) file_get_contents($this->repoRoot.'/conformance/compatibility/matrix.json'), true, flags: JSON_THROW_ON_ERROR);
        $archiveRel = (string) $matrix['prior']['archives']['php-contracts']['path'];
        $expectedSha = (string) $matrix['prior']['archives']['php-contracts']['sha256'];
        $archivePath = $this->repoRoot.'/conformance/compatibility/'.$archiveRel;
        $actualSha = hash_file('sha256', $archivePath);
        if ($actualSha !== $expectedSha) {
            throw new RuntimeException('Prior N-1 PHP contracts archive hash mismatch');
        }

        $tmp = $this->workDir.'/prior-archive-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/php-contracts', 0700, true);
        $zip = new \ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open pinned N-1 contracts archive');
        }
        $zip->extractTo($tmp.'/php-contracts');
        $zip->close();
        $this->copyDir($this->repoRoot.'/conformance/clients/php/src', $tmp.'/php-client/src');
        $this->copyDir($this->repoRoot.'/conformance/clients/php/bin', $tmp.'/php-client/bin');
        file_put_contents($tmp.'/php-client/bin/bootstrap.php', sprintf(
            "<?php\ndeclare(strict_types=1);\n\$root = %s;\nrequire %s;\nspl_autoload_register(static function (string \$class) use (\$root): void {\n    \$map = ['Billium\\\\ClientConformance\\\\' => \$root.'/php-client/src/', 'Billium\\\\Contracts\\\\' => \$root.'/php-contracts/src/'];\n    foreach (\$map as \$prefix => \$dir) {\n        if (str_starts_with(\$class, \$prefix)) {\n            \$p = \$dir.str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))).'.php';\n            if (is_file(\$p)) { require \$p; }\n            return;\n        }\n    }\n}, true, true);\n",
            var_export($tmp, true),
            var_export($this->repoRoot.'/vendor/autoload.php', true),
        ));
        file_put_contents($tmp.'/verify-shared.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/php-client/bin/bootstrap.php';
$in = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$codec = new \Billium\Contracts\Contracts\Codec;
$validRaw = json_decode($in['valid'], false, 64, JSON_THROW_ON_ERROR);
$tamperedRaw = json_decode($in['tampered'], false, 64, JSON_THROW_ON_ERROR);
$decoded = $codec->decode($in['valid']);
$fullDigest = $codec->digest($validRaw, 'digest');
$whitelist = clone $validRaw;
unset($whitelist->bridgeAdditiveExtension, $whitelist->digest);
$whitelistDigest = $codec->digest($whitelist, 'digest');
$tamperedDigest = $codec->digest($tamperedRaw, 'digest');
echo json_encode([
    'accepted' => $decoded->schema === 'payment.find.v1' && $fullDigest === $validRaw->digest,
    'fullDigest' => $fullDigest,
    'digestCoversUnknown' => isset($decoded->raw->bridgeAdditiveExtension) && $fullDigest !== $whitelistDigest,
    'tamperedRejected' => $tamperedDigest !== $tamperedRaw->digest,
], JSON_THROW_ON_ERROR);
PHP);

        $batch = [
            'op' => 'batch',
            'cases' => [
                ['id' => 'valid-ext', 'op' => 'project-status', 'jsonText' => $wireJson],
                ['id' => 'tampered-ext', 'op' => 'project-status', 'jsonText' => $tamperedJson],
                ['id' => 'current-only', 'op' => 'prepare', 'jsonText' => json_encode(['schema' => 'payment.authorize.v1'], JSON_THROW_ON_ERROR)],
            ],
        ];
        $currentRun = $this->runClientBatch($this->repoRoot.'/conformance/clients/php/bin/client.php', $batch, null);
        $priorRun = $this->runClientBatch($tmp.'/php-client/bin/client.php', $batch, ['BILLIUM_ARCHIVE_ROOT' => $tmp]);

        $sharedPayload = json_encode(['valid' => $sharedFindJson, 'tampered' => $tamperedFindJson], JSON_THROW_ON_ERROR);
        $priorSharedProc = new Process([PHP_BINARY, $tmp.'/verify-shared.php'], $this->repoRoot, null, $sharedPayload, 20);
        $priorSharedProc->mustRun();
        /** @var array{accepted: bool, fullDigest: string, digestCoversUnknown: bool, tamperedRejected: bool} $priorShared */
        $priorShared = json_decode($priorSharedProc->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $currentDecodedFind = $codec->decode($sharedFindJson);
        $currentFindDigest = $codec->digest(json_decode($sharedFindJson, false, 64, JSON_THROW_ON_ERROR), 'digest');

        return [
            'archiveShaVerified' => true,
            'currentAccepted' => $currentCheck['valid']
                && ($currentRun['valid-ext']['outcome'] ?? '') === 'accepted'
                && $currentDecodedFind->schema === 'payment.find.v1',
            'priorAccepted' => $priorShared['accepted'],
            'digestCoversUnknownBothGenerations' => $currentCheck['digestCoversUnknown']
                && ($currentRun['valid-ext']['digestCoversUnknown'] ?? false) === true
                && $priorShared['digestCoversUnknown'],
            'fullDigestMatchesAcrossGenerations' => $currentFindDigest === $priorShared['fullDigest'],
            'tamperedRejectedBothGenerations' => ! $tamperedCheck['valid']
                && ($currentRun['tampered-ext']['outcome'] ?? '') === 'refused'
                && $priorShared['tamperedRejected'],
            'priorRefusesCurrentOnlySchema' => (($priorRun['current-only']['error']['code'] ?? '') === 'billium.schema.unsupported')
                && (($priorRun['valid-ext']['error']['code'] ?? '') === 'billium.schema.unsupported'),
        ];
    }

    /**
     * @param  array<string, mixed>  $batch
     * @param  array<string, string>|null  $extraEnv
     * @return array<string, array<string, mixed>>
     */
    private function runClientBatch(string $script, array $batch, ?array $extraEnv): array
    {
        $env = array_merge($_ENV, getenv(), $extraEnv ?? []);
        unset($env['BILLIUM_AUTOLOAD']);
        $proc = new Process([PHP_BINARY, $script], $this->repoRoot, $env, json_encode($batch, JSON_THROW_ON_ERROR), 20);
        $proc->mustRun();
        /** @var array{results: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($proc->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $indexed = [];
        foreach ($decoded['results'] as $item) {
            $indexed[(string) $item['id']] = $item;
        }

        return $indexed;
    }

    private function copyDir(string $src, string $dst): void
    {
        mkdir($dst, 0700, true);
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $s = $src.'/'.$entry;
            $d = $dst.'/'.$entry;
            if (is_dir($s)) {
                $this->copyDir($s, $d);
            } else {
                copy($s, $d);
            }
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function rawHttp(string $method, string $path, string $body, array $headers): array
    {
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);
        $rawBody = @file_get_contents($this->producerUrl.$path, false, $ctx);
        if ($rawBody === false) {
            throw new RuntimeException("HTTP transport failed for {$method} {$path}");
        }
        $status = 0;
        $respHeaders = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$hk, $hv] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($hk))] = trim($hv);
            }
        }

        return ['status' => $status, 'headers' => $respHeaders, 'body' => $rawBody];
    }

    /**
     * @param  array{status: int, headers: array<string, string>, body: string}  $raw
     * @return array<string, mixed>
     */
    private function parseResponse(array $raw): array
    {
        $parsed = null;
        if ($raw['body'] !== '') {
            $decoded = json_decode($raw['body'], true);
            if (is_array($decoded)) {
                $parsed = $decoded;
            }
        }

        return [
            'status' => $raw['status'],
            'responseDigest' => 'sha256:'.hash('sha256', $raw['body']),
            'body' => $parsed,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function saveCursorState(string $key, array $state): void
    {
        $file = $this->workDir.'/cursor-'.$key.'.json';
        $tmp = $file.'.tmp';
        file_put_contents($tmp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
        rename($tmp, $file);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCursorState(string $key): array
    {
        $file = $this->workDir.'/cursor-'.$key.'.json';
        if (! is_file($file)) {
            throw new RuntimeException("Cursor state {$key} not initialized");
        }

        return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    private function gitCommit(): string
    {
        $proc = new Process(['git', 'rev-parse', 'HEAD'], $this->repoRoot);
        $proc->run();
        $out = trim($proc->getOutput());

        return preg_match('/^[0-9a-f]{7,64}$/', $out) === 1 ? $out : str_repeat('0', 40);
    }

    private function packageHash(): string
    {
        $files = [
            $this->repoRoot.'/packages/contracts/composer.json',
            $this->repoRoot.'/conformance/consumers/bridge/BridgeReferenceAdapter.php',
        ];
        $acc = '';
        foreach ($files as $file) {
            $acc .= hash_file('sha256', $file);
        }

        return 'sha256:'.hash('sha256', $acc);
    }
}
