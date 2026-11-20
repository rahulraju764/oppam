<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\Broker;
use App\Models\BrokerConsentDispute;
use App\Models\BrokerFraudSignal;
use App\Models\BrokerImportBatch;
use App\Models\BrokerKyc;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerStaff;
use App\Models\BrokerStaffInvitation;
use App\Models\Invoice;
use App\Models\ModerationItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Which audit subjects belong to an entity's timeline (PRD A12 "per-entity timeline"; P9.2a):
 * the entity itself plus the records it owns, as subject_type (class basename, as AuditLogger
 * writes it) => ids. Each list is capped at CAP ids (most recent first) so a long-lived entity
 * never builds an unbounded IN list; every lookup uses the (subject_type, subject_id, created_at) index.
 */
final class TimelineSubjects
{
    public const CAP = 200;

    /** @return array<string, list<string>> */
    public function forMember(User $member, Profile $profile): array
    {
        return array_filter([
            'User' => [(string) $member->id],
            'Profile' => [(string) $profile->id],
            'ModerationItem' => $this->ids(ModerationItem::class, 'profile_id', $profile->id),
            'Subscription' => $this->ids(Subscription::class, 'profile_id', $profile->id),
            'Order' => $this->ids(Order::class, 'profile_id', $profile->id),
            'Refund' => $this->ids(Refund::class, 'profile_id', $profile->id),
            'VerificationRequest' => $this->ids(VerificationRequest::class, 'profile_id', $profile->id),
        ]);
    }

    /** @return array<string, list<string>> */
    public function forBroker(Broker $broker): array
    {
        return array_filter([
            'Broker' => [(string) $broker->id],
            'BrokerStaff' => $this->ids(BrokerStaff::class, 'broker_id', $broker->id),
            'BrokerStaffInvitation' => $this->ids(BrokerStaffInvitation::class, 'broker_id', $broker->id),
            'BrokerKyc' => $this->ids(BrokerKyc::class, 'broker_id', $broker->id),
            'BrokerImportBatch' => $this->ids(BrokerImportBatch::class, 'broker_id', $broker->id),
            'BrokerPayoutItem' => $this->ids(BrokerPayoutItem::class, 'broker_id', $broker->id),
            'BrokerFraudSignal' => $this->ids(BrokerFraudSignal::class, 'broker_id', $broker->id),
            'BrokerConsentDispute' => $this->ids(BrokerConsentDispute::class, 'broker_id', $broker->id),
        ]);
    }

    /** @return array<string, list<string>> */
    public function forOrder(Order $order): array
    {
        return array_filter([
            'Order' => [(string) $order->id],
            'Payment' => $this->ids(Payment::class, 'order_id', $order->id),
            'Refund' => $this->ids(Refund::class, 'order_id', $order->id),
            'Invoice' => $this->ids(Invoice::class, 'order_id', $order->id),
            'Subscription' => $this->ids(Subscription::class, 'order_id', $order->id),
        ]);
    }

    /**
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    private function ids(string $model, string $column, string $value): array
    {
        return $model::query()->where($column, $value)->latest((new $model)->getKeyName())->limit(self::CAP)
            ->pluck((new $model)->getKeyName())->map(fn ($id): string => (string) $id)->values()->all();
    }
}
