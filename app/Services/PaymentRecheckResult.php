<?php

namespace App\Services;

use App\Enums\PaymentRecheckStatus;

final class PaymentRecheckResult
{
    public function __construct(
        public readonly PaymentRecheckStatus $status,
        public readonly ?string $gatewayPaymentId = null,
        public readonly ?string $message = null,
        public readonly array $context = [],
    ) {
    }

    public static function paid(?string $gatewayPaymentId, array $context = []): self
    {
        return new self(PaymentRecheckStatus::PAID, $gatewayPaymentId, null, $context);
    }

    public static function canceled(?string $gatewayPaymentId, array $context = []): self
    {
        return new self(PaymentRecheckStatus::CANCELED, $gatewayPaymentId, null, $context);
    }

    public static function processing(?string $gatewayPaymentId, array $context = []): self
    {
        return new self(PaymentRecheckStatus::PROCESSING, $gatewayPaymentId, null, $context);
    }

    public static function unverifiable(string $message, array $context = []): self
    {
        return new self(PaymentRecheckStatus::UNVERIFIABLE, null, $message, $context);
    }

    public static function providerFailure(string $message, array $context = []): self
    {
        return new self(PaymentRecheckStatus::PROVIDER_FAILURE, null, $message, $context);
    }
}
