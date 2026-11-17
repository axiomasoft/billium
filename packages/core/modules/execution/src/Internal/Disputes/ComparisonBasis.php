<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Disputes;

use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;

#[Internal]
final class ComparisonBasis
{
    /** The caller holds the original root and its contributing operations.
     * @return array<string,mixed>
     */
    public static function locked(Connection $db, OperationRecord $root, CapacitySnapshot $capacity): array
    {
        $scope = $root->binding->scope;
        $children = $db->table('billium_execution_operation')->where(['installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef,
            'environment' => $scope->environment, 'root_operation_ref' => $root->operationRef])->orderBy('operation_ref')
            ->get(['operation_ref', 'kind', 'revision', 'economic_digest'])->all();

        return ['status' => $root->state, 'rootRef' => $root->operationRef, 'rootRevision' => $root->revision,
            'contributingRevisionsDigest' => ExecutionCodec::digest($children), 'capturedMinor' => $capacity->confirmedCapture->minor,
            'refundedMinor' => $capacity->confirmedRefund->minor, 'financialHold' => $capacity->financialHold, 'currency' => $root->requested->currency->code];
    }
}
