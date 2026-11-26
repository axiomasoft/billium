<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Contracts\Data\Money;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProviderProcessingPortsTest extends TestCase
{
    public function test_historical_material_has_unknown_individual_amount_until_explicitly_authenticated(): void
    {
        $amount = Money::parse('0.00', 'RUB');
        $material = new MaterialSnapshot('op', EffectKind::Capture, 'account', 'RUB', $amount, $amount, $amount, $amount, 'declined', '1', null, 'digest', ['raw'], new DateTimeImmutable);
        $fields = get_object_vars($material);
        unset($fields['providerOperationId'], $fields['bindingDigest'], $fields['providerReference'], $fields['operationAmount']);
        $historical = ExecutionCodec::decode(MaterialSnapshot::class, ExecutionCodec::encode($fields));
        self::assertNull($historical->operationAmount);
        self::assertNull($historical->providerOperationId);
        $current = ExecutionCodec::decode(MaterialSnapshot::class, ExecutionCodec::encode($fields + ['providerOperationId' => 'provider-assigned', 'bindingDigest' => 'binding', 'providerReference' => 'request', 'operationAmount' => $amount]));
        self::assertEquals($current, ExecutionCodec::decode(MaterialSnapshot::class, ExecutionCodec::encode($current)));
        self::assertSame(0, $current->operationAmount?->minor);
    }
}
