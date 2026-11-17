<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Support;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Schedule\Contracts\AgreementRevisionGuard;
use Billium\Schedule\Data\ScheduleRevisionTerms;

/** Host double only; the production SPI requires authority serialized until root commit. */
final class HostGuard implements AgreementRevisionGuard
{
    public ?ScheduleRevisionTerms $approved = null;

    public bool $cancelled = false;

    public int $checks = 0;

    public function assertCurrent(TransactionContext $context, ScheduleRevisionTerms $terms): void
    {
        $context->assertParticipant('schedule');
        $this->checks++;
        if ($this->cancelled || $this->approved?->digest() !== $terms->digest()) {
            throw new ProblemException(problem: ProblemCode::PolicyUnknown, detail: 'host_revision_or_cancellation_changed');
        }
    }
}
