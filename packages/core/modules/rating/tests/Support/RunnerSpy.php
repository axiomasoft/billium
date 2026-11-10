<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Support;

use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Data\StoredOutcome;
use Closure;

final class RunnerSpy implements OperationRunner
{
    public int $roots = 0;

    public ?TransactionContext $replacement = null;

    public bool $loseReply = false;

    public ?OperationRunner $assertionAuthority = null;

    /** @var list<TransactionContext> */
    public array $contexts = [];

    /** @var list<string> */
    public array $assertions = [];

    public function __construct(private readonly OperationRunner $inner) {}

    public function run(OperationSpec $spec, Closure $body): StoredOutcome
    {
        $this->roots++;
        if ($this->replacement !== null) {
            return StoredOutcome::accepted($body($this->replacement));
        }
        $outcome = $this->inner->run(spec: $spec, body: function (TransactionContext $context) use ($body) {
            $this->contexts[] = $context;

            return $body($context);
        });
        if ($this->loseReply) {
            fwrite(STDOUT, "committed\n");
            fflush(STDOUT);
            sleep(30);
        }

        return $outcome;
    }

    public function assertCurrent(TransactionContext $context, ?string $moduleId = null): void
    {
        $this->assertions[] = $moduleId ?? '';
        ($this->assertionAuthority ?? $this->inner)->assertCurrent(context: $context, moduleId: $moduleId);
    }
}
