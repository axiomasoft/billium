<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Work;

use Billium\Collection\Contracts\ConsentQueries;
use Billium\Collection\Data\AuthorizeCollection;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\PlanCollectionStep;
use Billium\Collection\Internal\Canonical\CanonicalJson;
use Billium\Collection\Internal\Queries\DatabaseDunningQueries;
use Billium\Collection\Internal\Services\DatabaseDunningCaseService;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Execution\Data\ExecutionScope;
use Billium\Ledger\Contracts\LedgerQueries;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/** COV-328/D56: schedule one authenticated ledger keyset page using the host's selected mandate. */
#[Internal]
final readonly class DueCollectionWorker
{
    public function __construct(
        private CallerContext $callers,
        private ConsentQueries $consents,
        private LedgerQueries $ledger,
        private DatabaseDunningCaseService $cases,
        private DatabaseManager $databases,
        private BilliumOptions $options,
    ) {}

    /** @return array{authorized: list<CollectionStepView>, refused: int, nextCursor: ?string} */
    public function schedule(
        ExecutionScope $execution, string $executionProfile, int $routingEpoch, string $consentRef, int $consentVersion,
        int $collectionGeneration, DateTimeImmutable $asOf, int $limit = 100, ?string $cursor = null,
    ): array {
        $scope = new CollectionScope($execution->sellerRef, $execution->environment);
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        if ($caller->installationRef !== $execution->installationRef || ! in_array($execution->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)
            || ! in_array($executionProfile, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Collection worker is outside the authenticated route');
        }
        if ($limit < 1 || $limit > 100 || $asOf > new DateTimeImmutable) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid due collection page');
        }
        $binding = $this->consents->findBinding($scope, $consentRef, $consentVersion)
            ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown due collection mandate');
        $page = $this->ledger->dueObligations($binding->billingAccountRef, $asOf, $limit, $cursor);
        $items = $page['obligations'] ?? null;
        if (! is_array($items) || count($items) > $limit) {
            throw new LogicException('Ledger due query returned an invalid page');
        }
        $eligible = [];
        foreach ($items as $item) {
            if (is_array($item) && ($item['customerRef'] ?? null) === $binding->customerRef) {
                $eligible[RowCast::str($item['obligationRef'] ?? null, 'obligationRef')] = RowCast::integer($item['revision'] ?? null, 'revision');
            }
        }
        $rows = $this->databases->connection($this->options->connection)->table('billium_collection_dunning_case')
            ->where('seller_ref', $scope->sellerRef)->where('environment', $scope->environment)
            ->where('billing_account_ref', $binding->billingAccountRef)->where('collection_generation', $collectionGeneration)
            ->where('status', 'open')->whereNull('active_step_no')->where('current_open_minor', '>', 0)
            ->whereIn('obligation_ref', array_keys($eligible))->where('next_attempt_at', '<=', RowCast::formatUtc($asOf))
            ->whereRaw('recovery_deadline_at >= clock_timestamp()')->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get();
        $authorized = [];
        $refused = 0;
        foreach ($rows as $row) {
            $case = DatabaseDunningQueries::hydrateCase($row);
            $step = $case->lastCompletedStepNo + 1;
            $identity = substr(CanonicalJson::digest([$execution->installationRef, $scope->sellerRef, $scope->environment, $case->caseRef, $step]), 7);
            $key = CanonicalJson::digest([$identity, $case->caseRevision, $eligible[$case->obligationRef], $execution->intentOwnerNamespace,
                $executionProfile, $routingEpoch, $consentRef, $consentVersion]);
            $command = new AuthorizeCollection(
                new PlanCollectionStep('due:'.$key, $scope, $case->caseRef, 'step:'.$identity, $step, 'intent:'.$identity, 'charge:'.$identity,
                    $case->currentOpenMinor, $case->nextAttemptAt ?? throw new LogicException('Due case has no due time'), $case->caseRevision),
                $execution, $consentRef, $consentVersion, $routingEpoch, $executionProfile,
            );
            try {
                $authorized[] = $this->cases->authorizeDueCollection($command, $asOf);
            } catch (ProblemException $e) {
                if (! in_array($e->problem, [ProblemCode::OperationFinancialHold, ProblemCode::IdempotencyConflict], true)
                    && $e->detail !== 'billium.collection.due_case_busy') {
                    throw $e;
                }
                $refused++;
            }
        }

        return ['authorized' => $authorized, 'refused' => $refused, 'nextCursor' => RowCast::optStr($page['nextCursor'] ?? null, 'nextCursor')];
    }
}
