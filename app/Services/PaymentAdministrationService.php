<?php

namespace App\Services;

use App\Classes\PaymentExtension;
use App\Enums\PaymentRecheckStatus;
use App\Enums\PaymentStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Payment;
use App\Models\User;
use App\Traits\HandlesGatewayPayments;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentAdministrationService
{
    use HandlesGatewayPayments;

    public function canManuallyManage(Payment $payment): bool
    {
        return in_array($payment->status, [PaymentStatus::OPEN, PaymentStatus::PROCESSING], true);
    }

    public function forceConfirm(Payment $payment, ?User $actor = null): PaymentManualActionResult
    {
        if (!$this->canManuallyManage($payment)) {
            return PaymentManualActionResult::info('Only open or processing payments can be force confirmed.');
        }

        $oldStatus = $payment->status?->value;
        $gatewayPaymentId = $payment->payment_id ?: $this->manualGatewayReference($payment);

        try {
            $completed = self::completePayment($payment->id, $gatewayPaymentId);
        } catch (Throwable $e) {
            Log::error('Manual payment force confirmation failed.', [
                'payment_id' => $payment->id,
                'admin_user_id' => $actor?->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            report($e);

            return PaymentManualActionResult::error('Payment could not be force confirmed.');
        }

        if (!$completed) {
            return PaymentManualActionResult::info('Payment was not changed.');
        }

        $payment->refresh();

        Log::info('Payment force confirmed by admin.', [
            'payment_id' => $payment->id,
            'admin_user_id' => $actor?->id,
            'gateway_payment_id' => $payment->payment_id,
        ]);

        $this->recordAudit($payment, $actor, 'force confirmed payment', [
            'payment_id' => $payment->id,
            'action' => 'force_confirm',
            'verification' => 'forced',
            'payment_method' => $payment->payment_method,
            'old_status' => $oldStatus,
            'new_status' => $payment->status->value,
            'gateway_payment_id' => $payment->payment_id,
        ]);

        return PaymentManualActionResult::success('Payment has been force confirmed.');
    }

    public function recheck(Payment $payment, ?User $actor = null): PaymentManualActionResult
    {
        if (!$this->canManuallyManage($payment)) {
            return PaymentManualActionResult::info('Only open or processing payments can be rechecked.');
        }

        $paymentGatewayExtension = $this->resolveGatewayExtension($payment);
        if ($paymentGatewayExtension === null) {
            return PaymentManualActionResult::error('Unable to find the payment gateway for this payment.');
        }

        try {
            $oldStatus = $payment->status?->value;
            $recheckResult = $paymentGatewayExtension::recheckPayment($payment);
        } catch (Throwable $e) {
            Log::error('Manual payment status recheck failed.', [
                'payment_id' => $payment->id,
                'payment_method' => $payment->payment_method,
                'admin_user_id' => $actor?->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            report($e);

            return PaymentManualActionResult::error('Payment status could not be rechecked.');
        }

        $applied = $this->applyRecheckResult($payment, $recheckResult);

        if (!$applied->successful) {
            $payment->refresh();
            $this->recordAudit($payment, $actor, 'rechecked payment status', [
                'payment_id' => $payment->id,
                'action' => 'recheck',
                'verification' => 'provider',
                'payment_method' => $payment->payment_method,
                'old_status' => $oldStatus,
                'new_status' => $payment->status->value,
                'result' => $recheckResult->status->value,
                'message' => $recheckResult->message,
            ] + $recheckResult->context);

            return $applied;
        }

        $payment->refresh();

        Log::info('Payment status rechecked by admin.', [
            'payment_id' => $payment->id,
            'payment_method' => $payment->payment_method,
            'admin_user_id' => $actor?->id,
            'status' => $payment->status->value,
            'gateway_payment_id' => $payment->payment_id,
        ]);

        $this->recordAudit($payment, $actor, 'rechecked payment status', [
            'payment_id' => $payment->id,
            'action' => 'recheck',
            'verification' => 'provider',
            'payment_method' => $payment->payment_method,
            'old_status' => $oldStatus,
            'new_status' => $payment->status->value,
            'result' => $recheckResult->status->value,
            'status' => $payment->status->value,
            'gateway_payment_id' => $payment->payment_id,
        ] + $recheckResult->context);

        return PaymentManualActionResult::success('Payment status rechecked. Current status: :status', [
            'status' => $payment->status->value,
        ]);
    }

    private function applyRecheckResult(Payment $payment, PaymentRecheckResult $result): PaymentManualActionResult
    {
        if ($result->status === PaymentRecheckStatus::UNVERIFIABLE) {
            return PaymentManualActionResult::warning(
                $result->message ?: 'Payment status could not be rechecked. The gateway reference may be missing or unsupported.'
            );
        }

        if ($result->status === PaymentRecheckStatus::PROVIDER_FAILURE) {
            return PaymentManualActionResult::error(
                $result->message ?: 'Payment status could not be rechecked.'
            );
        }

        $gatewayPaymentId = $result->gatewayPaymentId;

        if ($result->status === PaymentRecheckStatus::PAID) {
            $completed = self::completePayment($payment->id, $gatewayPaymentId);

            return $completed
                ? PaymentManualActionResult::success('Payment status rechecked. Current status: :status', ['status' => PaymentStatus::PAID->value])
                : PaymentManualActionResult::info('Payment was not changed.');
        }

        if ($result->status === PaymentRecheckStatus::CANCELED) {
            self::setPaymentCanceled($payment->id, $gatewayPaymentId);

            return PaymentManualActionResult::success('Payment status rechecked. Current status: :status', ['status' => PaymentStatus::CANCELED->value]);
        }

        if ($result->status === PaymentRecheckStatus::PROCESSING) {
            self::setPaymentProcessing($payment->id, $gatewayPaymentId);

            return PaymentManualActionResult::success('Payment status rechecked. Current status: :status', ['status' => PaymentStatus::PROCESSING->value]);
        }

        return PaymentManualActionResult::warning('Payment status could not be rechecked. The gateway reference may be missing or unsupported.');
    }

    private function resolveGatewayExtension(Payment $payment): ?string
    {
        if (empty($payment->payment_method)) {
            return null;
        }

        $paymentGatewayExtension = ExtensionHelper::getExtensionClass($payment->payment_method);
        if (!$paymentGatewayExtension || !is_subclass_of($paymentGatewayExtension, PaymentExtension::class)) {
            return null;
        }

        return $paymentGatewayExtension;
    }

    private function manualGatewayReference(Payment $payment): string
    {
        return 'manual-' . $payment->id;
    }

    private function recordAudit(Payment $payment, ?User $actor, string $description, array $properties = []): void
    {
        try {
            $activity = activity()
                ->performedOn($payment)
                ->withProperties($properties);

            if ($actor !== null) {
                $activity->causedBy($actor);
            }

            $activity->log($description);
        } catch (Throwable $e) {
            Log::warning('Payment manual action audit log failed.', [
                'payment_id' => $payment->id,
                'description' => $description,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }
}
