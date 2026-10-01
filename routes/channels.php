<?php

declare(strict_types=1);

use App\Broadcasting\AdminQueuesChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels (PRD §9.3, §11A B.15)
|--------------------------------------------------------------------------
| Every callback must re-check the database (participant, not blocked, active staff,
| admin permission). A channel is never protected by its name alone.
*/

// Laravel notifications for one user. Users get ULID keys in P0.4, so compare as strings.
Broadcast::channel('App.Models.User.{id}', fn ($user, string $id): bool => (string) $user->id === $id);

// Live moderation / verification queue counts for admins (PRD §9.3, A04). Admin guard only;
// AdminQueuesChannel re-checks status, 2FA and permission against the database.
Broadcast::channel('admin.queues', AdminQueuesChannel::class, ['guards' => ['admin']]);
