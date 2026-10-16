<?php

declare(strict_types=1);

use App\Actions\Chat\SendMessage;
use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\SendInterest;
use App\Enums\PlanCode;
use App\Models\Conversation;
use App\Models\ImpersonationSession;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Support\Str;

/*
| Chat test helpers (P4.1, PRD M07). Need seedMasters() + PlansSeeder. A pair is connected the
| real way: the groom sends an interest, the bride accepts it (AcceptInterest opens the thread with
| its SYSTEM message).
*/

/** @return array{0: User, 1: User, 2: Conversation} groom, bride, their thread */
function chatConnected(?PlanCode $groomPlan = PlanCode::Gold, ?PlanCode $bridePlan = null): array
{
    $groom = groom();
    $bride = bride();
    app(SendInterest::class)->handle($groom, $bride->profile, 'T01');
    $thread = app(AcceptInterest::class)->handle($bride, $groom->profile);

    if ($groomPlan !== null) {
        chatOnPlan($groom, $groomPlan);
    }
    if ($bridePlan !== null) {
        chatOnPlan($bride, $bridePlan);
    }

    return [$groom->refresh(), $bride->refresh(), $thread->refresh()];
}

function chatOnPlan(User $member, PlanCode $plan): User
{
    Subscription::factory()->create(['profile_id' => $member->profile->id, 'plan_id' => Plan::query()->where('code', $plan->value)->sole()->id]);
    app(EntitlementService::class)->forget($member->profile);

    return $member;
}

function chatSend(User $from, User $to, string $body = 'Hello there', ?string $clientId = null): Message
{
    return app(SendMessage::class)->handle($from, $to->profile, $body, $clientId ?? (string) Str::uuid());
}

/** Act as an admin support session for this member (A01 impersonation). */
function chatImpersonate(User $member): void
{
    $running = ImpersonationSession::factory()->create(['user_id' => $member->id]);
    session([Impersonation::SESSION_KEY => $running->id]);
    app(Impersonation::class)->forget();
}
