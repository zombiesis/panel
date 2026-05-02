<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Events\CouponUsedEvent;
use App\Events\PaymentEvent;
use App\Events\UserUpdateCreditsEvent;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\ShopProduct;
use App\Models\User;
use App\Notifications\ConfirmPaymentNotification;
use App\Services\PaymentAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminPaymentManualActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_force_confirm_marks_payment_paid_and_dispatches_payment_events_once(): void
    {
        $payment = $this->createPayment([
            'payment_id' => null,
            'status' => PaymentStatus::PROCESSING,
        ]);
        $actor = User::factory()->create();

        Event::fake([
            CouponUsedEvent::class,
            PaymentEvent::class,
            UserUpdateCreditsEvent::class,
        ]);

        $result = app(PaymentAdministrationService::class)->forceConfirm($payment, $actor);
        $secondResult = app(PaymentAdministrationService::class)->forceConfirm($payment->fresh(), $actor);

        $this->assertTrue($result->successful);
        $this->assertSame('success', $result->flashLevel);
        $this->assertSame('info', $secondResult->flashLevel);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PAID->value,
            'payment_id' => 'manual-' . $payment->id,
        ]);

        Notification::assertSentTo($payment->user, ConfirmPaymentNotification::class);
        Event::assertDispatchedTimes(PaymentEvent::class, 1);
        Event::assertDispatchedTimes(UserUpdateCreditsEvent::class, 1);
    }

    public function test_force_confirm_marks_open_payment_paid(): void
    {
        $payment = $this->createPayment([
            'payment_id' => null,
            'status' => PaymentStatus::OPEN,
        ]);

        Event::fake([
            PaymentEvent::class,
            UserUpdateCreditsEvent::class,
        ]);

        $result = app(PaymentAdministrationService::class)->forceConfirm($payment, User::factory()->create());

        $this->assertTrue($result->successful);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PAID->value,
            'payment_id' => 'manual-' . $payment->id,
        ]);
        Event::assertDispatchedTimes(PaymentEvent::class, 1);
        Event::assertDispatchedTimes(UserUpdateCreditsEvent::class, 1);
    }

    public function test_force_confirm_does_not_mutate_terminal_payment(): void
    {
        $payment = $this->createPayment([
            'payment_id' => 'gateway-payment-id',
            'status' => PaymentStatus::PAID,
        ]);

        Event::fake([
            PaymentEvent::class,
            UserUpdateCreditsEvent::class,
        ]);

        $result = app(PaymentAdministrationService::class)->forceConfirm($payment, User::factory()->create());

        $this->assertFalse($result->successful);
        $this->assertSame('info', $result->flashLevel);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PAID->value,
            'payment_id' => 'gateway-payment-id',
        ]);

        Event::assertNotDispatched(PaymentEvent::class);
        Event::assertNotDispatched(UserUpdateCreditsEvent::class);
    }

    public function test_force_confirm_does_not_mutate_canceled_payment(): void
    {
        $payment = $this->createPayment([
            'payment_id' => 'gateway-payment-id',
            'status' => PaymentStatus::CANCELED,
        ]);

        Event::fake([
            PaymentEvent::class,
            UserUpdateCreditsEvent::class,
        ]);

        $result = app(PaymentAdministrationService::class)->forceConfirm($payment, User::factory()->create());

        $this->assertFalse($result->successful);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::CANCELED->value,
            'payment_id' => 'gateway-payment-id',
        ]);
        Event::assertNotDispatched(PaymentEvent::class);
        Event::assertNotDispatched(UserUpdateCreditsEvent::class);
    }

    public function test_recheck_reports_missing_gateway_without_mutating_payment(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'MissingGateway',
            'status' => PaymentStatus::OPEN,
        ]);

        $result = app(PaymentAdministrationService::class)->recheck($payment, User::factory()->create());

        $this->assertFalse($result->successful);
        $this->assertSame('error', $result->flashLevel);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::OPEN->value,
        ]);
    }

    public function test_mollie_provider_failure_does_not_mutate_payment(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'Mollie',
            'payment_id' => 'tr_provider_failure',
            'status' => PaymentStatus::PROCESSING,
        ]);
        $this->seedMollieSettings();

        Http::fake([
            'api.mollie.com/v2/payments/*' => Http::response(['error' => 'temporarily unavailable'], 503),
        ]);

        $result = app(PaymentAdministrationService::class)->recheck($payment, User::factory()->create());

        $this->assertFalse($result->successful);
        $this->assertSame('error', $result->flashLevel);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PROCESSING->value,
            'payment_id' => 'tr_provider_failure',
        ]);
    }

    public function test_mollie_recheck_verified_paid_marks_payment_paid(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'Mollie',
            'payment_id' => 'tr_paid',
            'status' => PaymentStatus::PROCESSING,
        ]);
        $this->seedMollieSettings();
        $this->fakeMolliePaymentResponse($payment, 'paid');

        Event::fake([
            PaymentEvent::class,
            UserUpdateCreditsEvent::class,
        ]);

        $result = app(PaymentAdministrationService::class)->recheck($payment, User::factory()->create());

        $this->assertTrue($result->successful);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PAID->value,
            'payment_id' => 'tr_paid',
        ]);
        Event::assertDispatchedTimes(PaymentEvent::class, 1);
        Event::assertDispatchedTimes(UserUpdateCreditsEvent::class, 1);
    }

    public function test_mollie_recheck_verified_pending_keeps_payment_processing(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'Mollie',
            'payment_id' => 'tr_pending',
            'status' => PaymentStatus::OPEN,
        ]);
        $this->seedMollieSettings();
        $this->fakeMolliePaymentResponse($payment, 'pending');

        $result = app(PaymentAdministrationService::class)->recheck($payment, User::factory()->create());

        $this->assertTrue($result->successful);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PROCESSING->value,
            'payment_id' => 'tr_pending',
        ]);
    }

    public function test_mollie_recheck_verified_canceled_marks_payment_canceled(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'Mollie',
            'payment_id' => 'tr_canceled',
            'status' => PaymentStatus::PROCESSING,
        ]);
        $this->seedMollieSettings();
        $this->fakeMolliePaymentResponse($payment, 'canceled');

        $result = app(PaymentAdministrationService::class)->recheck($payment, User::factory()->create());

        $this->assertTrue($result->successful);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::CANCELED->value,
            'payment_id' => 'tr_canceled',
        ]);
    }

    public function test_mollie_recheck_metadata_mismatch_does_not_mutate_payment(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'Mollie',
            'payment_id' => 'tr_wrong_metadata',
            'status' => PaymentStatus::PROCESSING,
        ]);
        $this->seedMollieSettings();

        Http::fake([
            'api.mollie.com/v2/payments/*' => Http::response([
                'id' => $payment->payment_id,
                'status' => 'paid',
                'metadata' => [
                    'payment_id' => 'not-' . $payment->id,
                ],
                'amount' => [
                    'value' => $this->gatewayAmount($payment),
                    'currency' => $payment->currency_code,
                ],
            ], 200),
        ]);

        $result = app(PaymentAdministrationService::class)->recheck($payment, User::factory()->create());

        $this->assertFalse($result->successful);
        $this->assertSame('warning', $result->flashLevel);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PROCESSING->value,
        ]);
    }

    public function test_mollie_recheck_amount_mismatch_cancels_payment(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'Mollie',
            'payment_id' => 'tr_bad_amount',
            'status' => PaymentStatus::PROCESSING,
        ]);
        $this->seedMollieSettings();

        Http::fake([
            'api.mollie.com/v2/payments/*' => Http::response([
                'id' => $payment->payment_id,
                'status' => 'paid',
                'metadata' => [
                    'payment_id' => $payment->id,
                ],
                'amount' => [
                    'value' => '999999.99',
                    'currency' => $payment->currency_code,
                ],
            ], 200),
        ]);

        $result = app(PaymentAdministrationService::class)->recheck($payment, User::factory()->create());

        $this->assertTrue($result->successful);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::CANCELED->value,
        ]);
    }

    public function test_mollie_recheck_currency_mismatch_cancels_payment(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'Mollie',
            'payment_id' => 'tr_bad_currency',
            'status' => PaymentStatus::PROCESSING,
            'currency_code' => 'USD',
        ]);
        $this->seedMollieSettings();

        Http::fake([
            'api.mollie.com/v2/payments/*' => Http::response([
                'id' => $payment->payment_id,
                'status' => 'paid',
                'metadata' => [
                    'payment_id' => $payment->id,
                ],
                'amount' => [
                    'value' => $this->gatewayAmount($payment),
                    'currency' => 'EUR',
                ],
            ], 200),
        ]);

        $result = app(PaymentAdministrationService::class)->recheck($payment, User::factory()->create());

        $this->assertTrue($result->successful);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::CANCELED->value,
        ]);
    }

    public function test_read_only_payment_permission_cannot_force_confirm(): void
    {
        $payment = $this->createPayment([
            'status' => PaymentStatus::PROCESSING,
        ]);
        $reader = User::factory()->create();
        $this->givePermission($reader, 'admin.payments.read');

        $response = $this->actingAs($reader)
            ->post(route('admin.payments.forceConfirm', $payment));

        $response->assertForbidden();
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PROCESSING->value,
        ]);
    }

    public function test_read_only_payment_permission_cannot_recheck(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'Mollie',
            'payment_id' => 'tr_read_only_recheck',
            'status' => PaymentStatus::PROCESSING,
        ]);
        $reader = User::factory()->create();
        $this->givePermission($reader, 'admin.payments.read');

        $response = $this->actingAs($reader)
            ->post(route('admin.payments.recheck', $payment));

        $response->assertForbidden();
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PROCESSING->value,
        ]);
    }

    public function test_manage_payment_permission_can_force_confirm_eligible_payment(): void
    {
        $payment = $this->createPayment([
            'payment_id' => null,
            'status' => PaymentStatus::OPEN,
        ]);
        $admin = User::factory()->create();
        $this->givePermission($admin, 'admin.payments.write');

        Event::fake([
            PaymentEvent::class,
            UserUpdateCreditsEvent::class,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.payments.forceConfirm', $payment));

        $response->assertRedirect();
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PAID->value,
        ]);
    }

    public function test_datatable_hides_mutation_actions_from_read_only_admin(): void
    {
        $this->createPayment([
            'status' => PaymentStatus::PROCESSING,
        ]);
        $reader = User::factory()->create();
        $this->givePermission($reader, 'admin.payments.read');

        $response = $this->actingAs($reader)
            ->get(route('admin.payments.datatable'));

        $response->assertOk();
        $this->assertStringNotContainsString('force-confirm', $response->getContent());
        $this->assertStringNotContainsString('recheck', $response->getContent());
    }

    public function test_datatable_renders_mutation_actions_for_payment_manager(): void
    {
        $this->createPayment([
            'status' => PaymentStatus::PROCESSING,
        ]);
        $admin = User::factory()->create();
        $this->givePermission($admin, 'admin.payments.read');
        $this->givePermission($admin, 'admin.payments.write');

        $response = $this->actingAs($admin)
            ->get(route('admin.payments.datatable'));

        $response->assertOk();
        $this->assertStringContainsString('force-confirm', $response->getContent());
        $this->assertStringContainsString('recheck', $response->getContent());
    }

    public function test_recheck_does_not_mutate_terminal_payment(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'Mollie',
            'payment_id' => 'tr_terminal',
            'status' => PaymentStatus::CANCELED,
        ]);
        $this->seedMollieSettings();
        $this->fakeMolliePaymentResponse($payment, 'paid');

        $result = app(PaymentAdministrationService::class)->recheck($payment, User::factory()->create());

        $this->assertFalse($result->successful);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::CANCELED->value,
        ]);
        Http::assertNothingSent();
    }

    public function test_recheck_does_not_mutate_paid_payment(): void
    {
        $payment = $this->createPayment([
            'payment_method' => 'Mollie',
            'payment_id' => 'tr_paid_terminal',
            'status' => PaymentStatus::PAID,
        ]);
        $this->seedMollieSettings();
        $this->fakeMolliePaymentResponse($payment, 'canceled');

        $result = app(PaymentAdministrationService::class)->recheck($payment, User::factory()->create());

        $this->assertFalse($result->successful);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PAID->value,
        ]);
        Http::assertNothingSent();
    }

    private function createPayment(array $attributes = []): Payment
    {
        $user = User::factory()->create();
        $shopProduct = ShopProduct::factory()->create();

        return Payment::factory()
            ->for($user)
            ->create(array_merge([
                'shop_item_product_id' => $shopProduct->id,
                'type' => $shopProduct->type,
                'amount' => $shopProduct->quantity,
                'price' => $shopProduct->price,
                'tax_value' => 0,
                'tax_percent' => 0,
                'total_price' => $shopProduct->price,
                'currency_code' => $shopProduct->currency_code,
            ], $attributes));
    }

    private function givePermission(User $user, string $permission): void
    {
        Permission::firstOrCreate([
            'name' => $permission,
            'guard_name' => 'web',
        ], [
            'readable_name' => $permission,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->givePermissionTo($permission);
    }

    private function seedMollieSettings(): void
    {
        foreach ([
            'api_key' => 'test_mollie_key',
            'webhook_secret' => 'test_webhook_secret',
            'enabled' => true,
        ] as $name => $payload) {
            DB::table('settings')->updateOrInsert([
                'group' => 'mollie',
                'name' => $name,
            ], [
                'payload' => json_encode($payload),
                'locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function fakeMolliePaymentResponse(Payment $payment, string $status): void
    {
        Http::fake([
            'api.mollie.com/v2/payments/*' => Http::response([
                'id' => $payment->payment_id,
                'status' => $status,
                'metadata' => [
                    'payment_id' => $payment->id,
                ],
                'amount' => [
                    'value' => $this->gatewayAmount($payment),
                    'currency' => $payment->currency_code,
                ],
            ], 200),
        ]);
    }

    private function gatewayAmount(Payment $payment): string
    {
        return number_format($payment->total_price / 1000, 2, '.', '');
    }
}
