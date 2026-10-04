<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastManager;

/** Use a real Pusher-protocol broadcaster (signatures), with the app's channels registered on it. */
function pusherBroadcasting(): void
{
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher' => ['driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret',
            'app_id' => 'test-app', 'options' => ['cluster' => 'ap2', 'useTLS' => true]],
    ]);
    app(BroadcastManager::class)->purge();
    require base_path('routes/channels.php');
}
