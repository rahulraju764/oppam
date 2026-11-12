<?php

declare(strict_types=1);

namespace App\Queries\Admin\Comms;

use App\Data\Comms\CampaignSegment;
use App\Enums\CommsChannel;
use App\Enums\NotificationEvent;
use App\Enums\PhotoStatus;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Who an A14 campaign reaches (P8.3b1): members (role MEMBER) whose account is ACTIVE and whose
 * profile is ACTIVE, matching the segment, and reachable on the campaign's channel:
 *  - email: a verified address; SMS: a verified phone;
 *  - and "Offers and announcements" (`admin_announcement`, not mandatory) allowed on that channel in
 *    /settings — in-app and email unless switched off, SMS only when switched ON (opt-in) — the
 *    same preference row NotificationRouter reads at send time.
 * Joined on profiles (one profile per member) so every filter is a plain column test. Used for the
 * live estimate in the builder and to freeze the audience at submit (SubmitCampaign).
 */
final class SegmentQuery
{
    /** @return Builder<User> */
    public function build(CampaignSegment $segment, CommsChannel $channel): Builder
    {
        $now = now();

        $query = User::query()
            ->join('profiles', 'profiles.user_id', '=', 'users.id')
            ->where('users.role', UserRole::Member->value)
            ->where('users.status', UserStatus::Active->value)
            ->whereNull('users.deleted_at')
            ->where('profiles.status', ProfileStatus::Active->value)
            ->whereNull('profiles.deleted_at');

        match ($channel) {
            CommsChannel::Email => $query->whereNotNull('users.email')->whereNotNull('users.email_verified_at'),
            CommsChannel::Sms => $query->whereNotNull('users.phone')->whereNotNull('users.phone_verified_at'),
            CommsChannel::InApp => null,
        };

        $column = match ($channel) {
            CommsChannel::Email => 'email',
            CommsChannel::Sms => 'sms',
            CommsChannel::InApp => 'in_app',
        };
        // Opt-out channels (in-app, email): no row = on, so exclude an explicit "off". Opt-in SMS
        // (NotificationEvent::smsIsOptIn): no row = off, so require an explicit "on".
        $event = NotificationEvent::AdminAnnouncement;
        $preference = fn (bool $value) => fn (QueryBuilder $q) => $q->selectRaw('1')->from('notification_preferences')
            ->whereColumn('notification_preferences.user_id', 'users.id')
            ->where('notification_preferences.event', $event->value)
            ->where('notification_preferences.'.$column, $value);
        $event->defaultFor($column)
            ? $query->whereNotExists($preference(false))
            : $query->whereExists($preference(true));

        $query
            ->when($segment->gender, fn (Builder $q, $gender) => $q->where('profiles.gender', $gender->value))
            ->when($segment->religionId, fn (Builder $q, int $id) => $q->where('profiles.religion_id', $id))
            ->when($segment->districtId, fn (Builder $q, int $id) => $q->where('profiles.district_id', $id))
            ->when($segment->completenessMin, fn (Builder $q, int $min) => $q->where('profiles.completeness', '>=', $min))
            ->when($segment->activeWithinDays, fn (Builder $q, int $days) => $q->where('profiles.last_active_at', '>=', $now->copy()->subDays($days)))
            ->when($segment->inactiveForDays, fn (Builder $q, int $days) => $q->where(fn (Builder $w) => $w
                ->whereNull('profiles.last_active_at')->orWhere('profiles.last_active_at', '<', $now->copy()->subDays($days))))
            ->when($segment->registeredFrom, fn (Builder $q, $from) => $q->where('users.created_at', '>=', $from->startOfDay()->utc()))
            ->when($segment->registeredTo, fn (Builder $q, $to) => $q->where('users.created_at', '<', $to->addDay()->startOfDay()->utc()))
            ->when($segment->verified !== null, fn (Builder $q) => $q->where('profiles.is_verified', $segment->verified))
            ->when($segment->viaBureau !== null, fn (Builder $q) => $segment->viaBureau
                ? $q->where(fn (Builder $w) => $w->whereNotNull('profiles.broker_id')->orWhereNotNull('profiles.claimed_at'))
                : $q->whereNull('profiles.broker_id')->whereNull('profiles.claimed_at'));

        if ($segment->hasPhoto !== null) {
            $photo = fn (QueryBuilder $q) => $q->selectRaw('1')->from('media')
                ->where('media.model_type', (new Profile)->getMorphClass())
                ->whereColumn('media.model_id', 'profiles.id')
                ->where('media.collection_name', Profile::PHOTOS)
                ->where('media.moderation_status', PhotoStatus::Approved->value);
            $segment->hasPhoto ? $query->whereExists($photo) : $query->whereNotExists($photo);
        }

        if ($segment->plan !== null) {
            $plan = $segment->plan;
            $current = fn (QueryBuilder $q) => $q->select(DB::raw(1))
                ->from('subscriptions')
                ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->whereColumn('subscriptions.profile_id', 'profiles.id')
                ->where('subscriptions.status', SubscriptionStatus::Active->value)
                ->whereNull('subscriptions.paused_at')
                ->where('subscriptions.starts_at', '<=', $now)
                ->where('subscriptions.ends_at', '>', $now)
                ->when($plan !== PlanCode::Free, fn (QueryBuilder $q) => $q->where('plans.code', $plan->value));
            $plan === PlanCode::Free ? $query->whereNotExists($current) : $query->whereExists($current);
        }

        return $query;
    }

    public function count(CampaignSegment $segment, CommsChannel $channel): int
    {
        return $this->build($segment, $channel)->count('users.id');
    }
}
