<?php

declare(strict_types=1);

use App\Actions\Billing\CreateOrder;
use App\Actions\Billing\HandlePaymentWebhook;
use App\Enums\PlanCode;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\FakePaymentGateway;
use Illuminate\Testing\TestResponse;

/*
| Billing test helpers (P5.1). Tests run with PAYMENT_DRIVER=fake (the default in `testing`): the fake
| gateway creates orders in the cache and signs webhooks exactly as Razorpay would.
*/

function fakeGateway(): FakePaymentGateway
{
    return app(FakePaymentGateway::class);
}

/** An order created through the real Action (amount computed by the server). */
function checkoutOrder(User $member, PlanCode $plan = PlanCode::Gold, int $months = 1, ?string $coupon = null): Order
{
    return app(CreateOrder::class)->handle($member, $plan, $months, $coupon);
}

/** POST a signed webhook delivery to /webhooks/razorpay the way Razorpay does. */
function postWebhook(array $delivery, ?string $signature = null): TestResponse
{
    return test()->call('POST', '/webhooks/razorpay', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? $delivery['signature'],
        'HTTP_X_RAZORPAY_EVENT_ID' => $delivery['event_id'],
    ], $delivery['body']);
}

/** The gateway captures the order (by default its full amount) and the webhook is delivered. */
function capturePayment(Order $order, ?int $amount = null): string
{
    $delivery = fakeGateway()->capture((string) $order->gateway_order_id, $amount);

    return app(HandlePaymentWebhook::class)->handle($delivery['body'], $delivery['signature'], $delivery['event_id']);
}
