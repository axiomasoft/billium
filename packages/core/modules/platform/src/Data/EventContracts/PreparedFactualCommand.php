<?php

declare(strict_types=1);

namespace Billium\Platform\Data\EventContracts;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** The route is fixed by type. A DTO alone neither authenticates its source nor grants admission. */
#[Api(since: '1.0')]
final readonly class PreparedFactualCommand
{
    public const string OWNER_MODULE_ID = 'execution';

    public const string COMMAND_TYPE = 'payment.observation.v1';

    public const string COMMAND_SCHEMA = 'billium.command.v1';

    public const string DESTINATION = 'ledger.inbox.payment_observation';

    public function __construct(public string $commandRef, public string $businessRef, public PaymentObservation $observation)
    {
        foreach ([$commandRef, $businessRef] as $identity) {
            if ($identity === '' || strlen($identity) > 200) {
                throw new InvalidArgumentException('Factual command identity must be bounded and nonempty');
            }
        }
    }
}
