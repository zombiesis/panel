<?php

namespace App\Enums;

enum PaymentRecheckStatus: string
{
    case PAID = 'paid';
    case CANCELED = 'canceled';
    case PROCESSING = 'processing';
    case UNVERIFIABLE = 'unverifiable';
    case PROVIDER_FAILURE = 'provider_failure';
}
