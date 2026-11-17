<?php

declare(strict_types=1);

namespace Billium\Contracts\Exceptions;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Errors\BilliumErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Exceptions\CodedException;

/**
 * Every refusal of decode and money operations: a registered problem code and a diagnostic detail.
 */
#[Api(since: '1.0')]
final class ProblemException extends CodedException
{
    public function __construct(public readonly ProblemCode $problem, public readonly string $detail = '')
    {
        parent::__construct($detail === '' ? $problem->value : $problem->value.': '.$detail);
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        $code = BilliumErrorCode::fromProblemCode($this->problem);
        $details = ['problem' => $this->problem->value];

        if ($this->detail !== '') {
            $sanitized = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $this->detail);
            $safeDetail = trim(is_string($sanitized) ? $sanitized : '');

            if ($safeDetail !== '') {
                $details['detail'] = mb_strcut($safeDetail, 0, 1024, 'UTF-8');
            }
        }

        return new ErrorDescriptor(
            code: $code,
            category: $code->category(),
            retryable: $code->isRetryable(),
            details: $details,
        );
    }
}
