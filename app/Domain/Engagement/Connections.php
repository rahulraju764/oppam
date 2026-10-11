<?php

declare(strict_types=1);

namespace App\Domain\Engagement;

use App\Enums\AccessRequestStatus;
use App\Enums\AccessRequestType;
use App\Enums\InterestStatus;
use App\Models\AccessRequest;
use App\Models\Interest;
use App\Models\Profile;

/**
 * Who is connected to whom (PRD M06): two members are CONNECTED once an interest between them —
 * in either direction — was accepted. THE place that answers it; the full last name, the Chat
 * button, ACCEPTED_ONLY photos / phone / horoscope all ask here. Also answers "did the owner
 * approve this member's photo / horoscope request?".
 *
 * One query per member and request (memoised), so a page of twenty cards costs the same as one.
 * Scoped to the request (AppServiceProvider); Actions that change a connection call forget().
 */
final class Connections
{
    public const NONE = 'none';

    public const SENT = 'sent';

    public const RECEIVED = 'received';

    public const CONNECTED = 'connected';

    /** @var array<string, array<string, true>> profile id => connected profile ids */
    private array $connected = [];

    /** @var array<string, array<string, true>> viewer profile id => "TYPE|owner id" approved */
    private array $grants = [];

    /** Pass the VIEWER first: the memo is per first profile (the relation itself is symmetric). */
    public function isConnected(Profile $a, Profile $b): bool
    {
        return isset($this->connectedTo($a)[$b->id]);
    }

    /** The owner approved this viewer's request of that type. */
    public function hasGrant(AccessRequestType $type, Profile $owner, Profile $viewer): bool
    {
        $this->grants[$viewer->id] ??= AccessRequest::query()
            ->where('requester_profile_id', $viewer->id)
            ->where('status', AccessRequestStatus::Approved->value)
            ->get(['type', 'owner_profile_id'])
            ->mapWithKeys(fn (AccessRequest $r): array => [$r->type->value.'|'.$r->owner_profile_id => true])
            ->all();

        return isset($this->grants[$viewer->id][$type->value.'|'.$owner->id]);
    }

    /**
     * Where the member stands with each of these profiles, for the Interest / Chat buttons:
     * connected, interest sent (pending), interest received (pending) — or absent (none).
     *
     * @param  list<string>  $profileIds
     * @return array<string, string> profile id => SENT | RECEIVED | CONNECTED
     */
    public function statesFor(Profile $me, array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $states = [];
        $rows = Interest::query()
            // A PENDING interest past its expiry is as good as expired, even before the hourly job marks it.
            ->where(fn ($q) => $q->where('status', InterestStatus::Accepted->value)
                ->orWhere(fn ($x) => $x->where('status', InterestStatus::Pending->value)->where('expires_at', '>', now())))
            ->where(fn ($q) => $q
                ->where(fn ($x) => $x->where('from_profile_id', $me->id)->whereIn('to_profile_id', $profileIds))
                ->orWhere(fn ($x) => $x->where('to_profile_id', $me->id)->whereIn('from_profile_id', $profileIds)))
            ->get(['from_profile_id', 'to_profile_id', 'status']);

        foreach ($rows as $row) {
            $mine = $row->from_profile_id === $me->id;
            $other = $mine ? $row->to_profile_id : $row->from_profile_id;

            // Connected wins over a pending interest in the other direction.
            if ($row->status === InterestStatus::Accepted) {
                $states[$other] = self::CONNECTED;
            } elseif (($states[$other] ?? null) !== self::CONNECTED) {
                $states[$other] = $mine ? self::SENT : self::RECEIVED;
            }
        }

        return $states;
    }

    public function stateWith(Profile $me, Profile $other): string
    {
        return $this->statesFor($me, [$other->id])[$other->id] ?? self::NONE;
    }

    /** Forget what this request resolved (after an interest or a request changed). */
    public function forget(): void
    {
        $this->connected = [];
        $this->grants = [];
    }

    /** @return array<string, true> */
    private function connectedTo(Profile $profile): array
    {
        return $this->connected[$profile->id] ??= Interest::query()
            ->where('status', InterestStatus::Accepted->value)
            ->where(fn ($q) => $q->where('from_profile_id', $profile->id)->orWhere('to_profile_id', $profile->id))
            ->get(['from_profile_id', 'to_profile_id'])
            ->mapWithKeys(fn (Interest $i): array => [($i->from_profile_id === $profile->id ? $i->to_profile_id : $i->from_profile_id) => true])
            ->all();
    }
}
