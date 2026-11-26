<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Admission;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Envelopes\RequestRefund;
use Billium\Contracts\Data\Envelopes\VoidAuthorization;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CommandInputReplay
{
    public function __construct(private IdempotencyReceiptQueries $receipts) {}

    /** The validated command fingerprint excludes transport metadata. The runner claim and provider request have their own digests. */
    public static function digest(ExecutePayment|AuthorizePayment|CapturePayment|VoidAuthorization|RequestRefund $command): string
    {
        $input = get_object_vars($command->raw);
        unset($input['requestedAt'], $input['commandKey']);
        $input['money'] = $command->money->toWire();

        return new Codec()->digest($input);
    }

    public function find(FinancialScope $scope, string $schema, string $qualifiedKey, string $inputDigest): ?StoredOutcome
    {
        $receipt = $this->receipts->byCommandKey($scope, $schema, $qualifiedKey);
        if ($receipt?->inputDigest === null) {
            return null;
        }
        if (! hash_equals($receipt->inputDigest, $inputDigest)) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'The retained claim has different command input');
        }

        return $receipt->outcome->asReplay();
    }
}
