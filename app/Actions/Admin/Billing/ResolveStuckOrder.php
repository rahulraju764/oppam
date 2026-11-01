<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Actions\Admin\Billing\Concerns\AuthorizesBilling;
use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\ReconcileOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Admin\BillingRefused;
use App\Exceptions\Billing\PaymentGatewayUnavailable;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A06 stuck orders. Re-poll (`billing.refund`): ask the gateway again, the same idempotent path as
 * a webhook. Mark failed (`billing.refund`, reason): only while no money is held. Force-activate
 * (`billing.force_activate` AND a super admin, reason): activates with the captured payment that
 * was held back, the same path as a webhook. All audited.
 */
final class ResolveStuckOrder
{
    use AuthorizesBilling;

    public const MARKED_FAILED = 'marked_failed';

    public function __construct(
        private readonly ReconcileOrder $reconcile,
        private readonly ActivateSubscription $activate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws BillingRefused gateway unreachable
     */
    public function repoll(AdminUser $admin, Order $order): Order
    {
        $this->authorizeFor($admin, 'billing.refund');
        $before = ['status' => $order->status->value];

        try {
            $fresh = $this->reconcile->poll($order);
        } catch (PaymentGatewayUnavailable $e) {
            report($e);

            throw BillingRefused::gatewayUnavailable();
        }

        $this->audit->record('billing.order_repolled', $fresh, $before, ['status' => $fresh->status->value], actor: $admin, subjectLabel: $fresh->code);

        return $fresh;
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BillingRefused paid, or a captured payment is held
     */
    public function markFailed(AdminUser $admin, Order $order, string $reason): Order
    {
        $this->authorizeFor($admin, 'billing.refund');
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $order, $reason): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === OrderStatus::Paid) {
                throw BillingRefused::orderAlreadyPaid();
            }
            if (Payment::query()->where('order_id', $locked->id)->where('status', PaymentStatus::Captured)->exists()) {
                throw BillingRefused::orderHoldsMoney();
            }

            $before = ['status' => $locked->status->value];
            $locked->forceFill(['status' => OrderStatus::Failed, 'failure_reason' => self::MARKED_FAILED])->save();
            $this->audit->record('billing.order_marked_failed', $locked, $before, ['status' => OrderStatus::Failed->value], reason: $reason, actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BillingRefused not a super admin, paid, or no captured payment
     */
    public function forceActivate(AdminUser $admin, Order $order, string $reason): Order
    {
        $this->authorizeFor($admin, 'billing.force_activate');
        if (! $admin->hasRole('super_admin')) {
            throw BillingRefused::superAdminOnly();
        }
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($admin, $order, $reason): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === OrderStatus::Paid) {
                throw BillingRefused::orderAlreadyPaid();
            }
            $payment = Payment::query()->where('order_id', $locked->id)->where('status', PaymentStatus::Captured)
                ->orderBy('captured_at')->orderBy('id')->lockForUpdate()->first() ?? throw BillingRefused::noCapturedPayment();

            $before = ['status' => $locked->status->value, 'failure_reason' => $locked->failure_reason];
            $this->activate->activateWith($locked, $payment);
            $this->audit->record('billing.order_force_activated', $locked, $before, [
                'status' => OrderStatus::Paid->value, 'order_total_paise' => $locked->total_paise, 'captured_paise' => $payment->amount_paise,
            ], reason: $reason, actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });
    }
}
