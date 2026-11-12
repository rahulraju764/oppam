<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Enums\CommsChannel;
use App\Enums\JourneyKey;
use App\Models\AdminUser;
use App\Models\AutomatedJourney;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Configure one A14 automated journey (P8.3b2, `campaigns.send`): on / off, its day offsets (1–365,
 * up to three, stored ascending without repeats) and its one channel — only a channel the journey's
 * event goes out on. Takes effect on the next daily run, without a deploy. Audited before / after
 * with the admin's reason. Which journey is decided by its key (an enum), never a client id.
 */
final class SaveJourney
{
    public const MAX_OFFSETS = 3;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<array-key, mixed>  $offsets  from the form: re-indexed, then validated
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, JourneyKey $key, bool $isActive, array $offsets, string $channel, string $reason): AutomatedJourney
    {
        Gate::forUser($admin)->authorize('campaigns.send');

        $event = $key->event();
        $data = Validator::make(
            ['offsets' => array_values($offsets), 'channel' => $channel, 'reason' => trim($reason)],
            [
                'offsets' => ['required', 'array', 'min:1', 'max:'.self::MAX_OFFSETS],
                'offsets.*' => ['required', 'integer', 'min:1', 'max:365', 'distinct'],
                'channel' => ['required', Rule::in(array_values(array_filter(
                    array_map(fn (CommsChannel $c): string => $c->value, CommsChannel::cases()),
                    fn (string $c): bool => $event->usesChannel($c),
                )))],
                'reason' => ['required', 'string', 'min:5', 'max:500'],
            ],
            ['offsets.*.distinct' => __('Each day can be used once.')],
        )->validate();

        $days = array_map('intval', $data['offsets']);
        sort($days);

        return DB::transaction(function () use ($admin, $key, $isActive, $days, $data): AutomatedJourney {
            $journey = AutomatedJourney::query()->where('key', $key->value)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($journey);

            $journey->forceFill([
                'is_active' => $isActive,
                'offsets' => $days,
                'channel' => CommsChannel::from($data['channel']),
            ])->save();

            $this->audit->record('comms.journey_updated', $journey, $before, $this->snapshot($journey), $data['reason'], $admin, $key->value);

            return $journey;
        });
    }

    /** @return array{is_active: bool, offsets: list<int>, channel: string} */
    private function snapshot(AutomatedJourney $journey): array
    {
        return ['is_active' => $journey->is_active, 'offsets' => $journey->offsets, 'channel' => $journey->channel->value];
    }
}
