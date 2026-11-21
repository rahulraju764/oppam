<?php

declare(strict_types=1);

namespace App\Enums;

/** A12 system health: a per-minute sample kept in health_samples (SampleSystemHealth, MeasureBroadcastLag). */
enum HealthMetric: string
{
    /** Open WebSocket connections on the Reverb / Pusher app; null = server unreachable. */
    case ReverbConnections = 'reverb_connections';

    /** Milliseconds a canary job waited on the `broadcasts` queue before a worker ran it. */
    case BroadcastLagMs = 'broadcast_lag_ms';
}
