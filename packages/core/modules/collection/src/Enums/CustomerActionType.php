<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum CustomerActionType: string
{
    case ThreeDsChallenge = 'three_ds_challenge';
    case MandateConfirmation = 'mandate_confirmation';
    case RedirectAuthentication = 'redirect_authentication';
    case ScaChallenge = 'sca_challenge';
}
