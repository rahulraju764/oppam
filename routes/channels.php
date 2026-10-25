<?php

declare(strict_types=1);

use App\Broadcasting\AdminDashboardChannel;
use App\Broadcasting\AdminQueuesChannel;
use App\Broadcasting\BrokerChannel;
use App\Broadcasting\BrokerOwnerChannel;
use App\Broadcasting\ChatChannel;
use App\Broadcasting\InboxChannel;
use App\Broadcasting\OnlinePresenceChannel;
use App\Broadcasting\PresenceChatChannel;
use App\Broadcasting\UserChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels (PRD §9.3, §11A B.15)
|--------------------------------------------------------------------------
| Every callback must re-check the database (participant, not blocked, active staff,
| admin permission). A channel is never protected by its name alone. Member channels use the
| `web` guard (the member site's /broadcasting/auth), admin channels the `admin` guard.
| Broker channels: `broker-owner.{broker}` from P7.2 (commission); `broker.{broker}` from P7.3 (moderation).
*/

// Notifications, signals and forced logout for one member.
Broadcast::channel('App.Models.User.{id}', UserChannel::class, ['guards' => ['web']]);

// Conversation list order + unread counts for the profile's owner.
Broadcast::channel('inbox.{profile}', InboxChannel::class, ['guards' => ['web']]);

// A chat thread: participants only, not CLOSED, no block between the pair.
Broadcast::channel('chat.{conversation}', ChatChannel::class, ['guards' => ['web']]);
Broadcast::channel('presence-chat.{conversation}', PresenceChatChannel::class, ['guards' => ['web']]);

// Site-wide online roster (green dots): codes only; see OnlinePresenceChannel for who may join.
Broadcast::channel('presence.online', OnlinePresenceChannel::class, ['guards' => ['web']]);

// Live moderation / verification queue counts for admins (PRD §9.3, A04). Admin guard only;
// AdminQueuesChannel re-checks status, 2FA and permission against the database.
Broadcast::channel('admin.queues', AdminQueuesChannel::class, ['guards' => ['admin']]);

// Live KPI ticks on the admin dashboard (PRD §9.3, A02). Admin guard only; AdminDashboardChannel
// re-checks status, 2FA and `dashboard.view` against the database.
Broadcast::channel('admin.dashboard', AdminDashboardChannel::class, ['guards' => ['admin']]);

// A bureau's Owner-only events (§11A B.15): commission earned (B.10). BrokerOwnerChannel re-checks
// that the caller is that bureau's ACTIVE Owner on an open bureau.
Broadcast::channel('broker-owner.{broker}', BrokerOwnerChannel::class, ['guards' => ['web']]);

// A bureau's shared events (§11A B.15): managed-profile moderation (P7.3), later imports and the
// client inbox. BrokerChannel re-checks an ACTIVE staff row of that bureau on an open bureau.
Broadcast::channel('broker.{broker}', BrokerChannel::class, ['guards' => ['web']]);
