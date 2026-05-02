<?php

namespace App\Services;

final class PaymentManualActionResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly string $flashLevel,
        public readonly string $message,
        public readonly array $context = [],
    ) {
    }

    public static function success(string $message, array $context = []): self
    {
        return new self(true, 'success', $message, $context);
    }

    public static function info(string $message, array $context = []): self
    {
        return new self(false, 'info', $message, $context);
    }

    public static function warning(string $message, array $context = []): self
    {
        return new self(false, 'warning', $message, $context);
    }

    public static function error(string $message, array $context = []): self
    {
        return new self(false, 'error', $message, $context);
    }
}
