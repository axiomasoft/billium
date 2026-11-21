<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Idempotency;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelope;
use Billium\Contracts\Data\Envelopes\CommandReceipt;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Closure;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class PinnedCommand
{
    public FinancialScope $scope;

    public string $inputDigest;

    public string $schema;

    public function __construct(
        Caller $caller, Envelope $command, string $installation, string $seller, string $environment, public string $key,
    ) {
        if ($installation !== $caller->installationRef || $seller !== $caller->sellerRef || $environment !== $caller->environment) {
            throw new ProblemException(ProblemCode::AuthDenied);
        }
        $this->scope = new FinancialScope($installation, $seller, $environment, null);
        $this->schema = $command->schema;
        $this->inputDigest = (new Codec)->digestOf($command);
    }

    public function kind(): string
    {
        return 'wire.'.$this->schema;
    }

    /**
     * @param  Closure(TransactionContext): Receipt  $body
     * @return Closure(TransactionContext): Receipt
     */
    public static function retaining(?self $wire, Closure $body): Closure
    {
        if ($wire === null) {
            return $body;
        }

        return static fn (TransactionContext $context): Receipt => $wire->receipt($body($context));
    }

    private function receipt(Receipt $original): Receipt
    {
        $codec = new Codec;
        $body = [
            'schema' => CommandReceipt::SCHEMA,
            'installationRef' => $this->scope->installationRef,
            'sellerRef' => $this->scope->sellerRef,
            'environment' => $this->scope->environment,
            'commandSchema' => $this->schema,
            'commandKey' => $this->key,
            'receiptRef' => 'command:'.hash('sha256', $codec->canonicalize([get_object_vars($this->scope), $this->schema, $this->key])),
            'receipt' => 'CommandAccepted',
        ];
        $body['digest'] = $codec->digest($body);

        return new PlatformReceipt($original->receiptClass(), [...$original->body(),
            'receiptRef' => $body['receiptRef'], 'wireReceipt' => $body]);
    }

    public function result(StoredOutcome $stored): CommandReceipt
    {
        $codec = new Codec;
        $receipt = $codec->decode($codec->canonicalize($stored->orThrow()->body['wireReceipt'] ?? null));
        assert($receipt instanceof CommandReceipt);

        return $receipt;
    }
}
