<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Tests\Support\UsesFreshProfileDatabase;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Billium\Ledger\Tests\Observation\OverRefundVector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The observed actual projection and its hold are owned by the ledger in
 * every delivery/authority profile and do not depend on execution registration. The same wire vector from an
 * embedded-commerz source and from a standalone-billium source gives applied 100/70, observed 140, exposure 40,
 * hold, spendable 0 and the typed financial-hold allocation refusal; the ledger issues no PSP command.
 */
#[Group('D62')]
#[Group('pg')]
final class ObservedHoldAllProfilesTest extends ObservationTestCase
{
    use OverRefundVector;
    use UsesFreshProfileDatabase;

    /** @return array<string, array{0: string}> */
    public static function profiles(): array
    {
        return ['embedded-commerz' => ['embedded-commerz'], 'standalone-billium' => ['standalone-billium']];
    }

    #[DataProvider('profiles')]
    public function test_the_same_vector_is_held_identically_by_the_ledger(string $authority): void
    {
        ['held' => $held, 'refusal' => $refusal] = $this->overRefund($authority);

        $applied = self::arr($held->body['applied']);
        $observed = self::arr($held->body['observed']);
        $discrepancy = self::arr($held->body['discrepancy']);
        self::assertSame(['10000', '7000'], [$applied['captured_minor'], $applied['refunded_minor']], 'applied 100/70');
        self::assertSame(['10000', '14000'], [$observed['captured_minor'], $observed['refunded_minor']], 'observed 140');
        self::assertSame('4000', $discrepancy['exposure_minor'], 'exposure 40');
        self::assertTrue($held->body['financial_hold']);
        self::assertSame('0', $held->body['spendable_minor']);
        self::assertSame(ProblemCode::AllocationReallocationRequired->value, $refusal);

        self::assertSame($authority, $this->observedRow()['source_authority']);
        self::assertSame($authority, $this->paymentRow()['source_authority']);
        self::assertTrue($this->paymentRow()['financial_hold']);
        self::assertSame(0, $this->rows('billium_outbox_command'), 'no direct or queued PSP call from the ledger');
        self::assertSame(0, self::integer($this->value("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'billium_execution%'")),
            'no execution module is installed: the projection does not depend on its registration');
        $ledger = $this->application()->make(ModuleRegistry::class)->descriptor('ledger');
        self::assertNotNull($ledger);
        self::assertSame(['platform'], $ledger->requiredModuleIds());
    }
}
