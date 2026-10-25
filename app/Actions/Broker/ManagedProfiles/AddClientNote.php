<?php

declare(strict_types=1);

namespace App\Actions\Broker\ManagedProfiles;

use App\Data\Broker\ClientNoteData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BureauPermission;
use App\Enums\ClientNoteType;
use App\Models\BrokerClientNote;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A note, call or meeting on a managed client, with an optional follow-up date (§11A B.6 "client
 * notes & reminders"): `client_notes.write`, inside ManagedProfileScope. Internal to the bureau —
 * never shown to members. Follow-ups are today or later (IST); the 08:30 FollowUpDue reminder
 * picks them up. Capped per login per hour.
 */
final class AddClientNote
{
    public const BODY_MAX = 2000;

    private const PER_HOUR = 120;

    public function __construct(
        private readonly BureauGate $gate,
        private readonly ManagedProfileScope $scope,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @throws HttpException 403 without the permission
     * @throws ModelNotFoundException<Profile>
     * @throws ValidationException
     */
    public function handle(User $actor, string $code, ClientNoteData $data): BrokerClientNote
    {
        $staff = $this->gate->authorize($actor, BureauPermission::ClientNotesWrite);
        $profile = $this->scope->find($staff, $code);

        $today = now(config('oppam.display_timezone'))->format('Y-m-d');
        $values = Validator::make($data->toArray(), [
            'type' => ['required', Rule::enum(ClientNoteType::class)],
            'outcome' => ['nullable', 'string', 'max:40'],
            'body' => ['required', 'string', 'min:2', 'max:'.self::BODY_MAX],
            'follow_up_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today],
        ], ['follow_up_on.after_or_equal' => __('Choose today or a later date.')], [
            'type' => __('type'), 'outcome' => __('outcome'), 'body' => __('note'), 'follow_up_on' => __('follow-up date'),
        ])->validate();

        $key = 'broker-client-note:'.$actor->id;
        if ($this->limiter->tooManyAttempts($key, self::PER_HOUR)) {
            throw ValidationException::withMessages(['body' => __('You have added many notes in the last hour. Please try again a little later.')]);
        }
        $this->limiter->hit($key, 3600);

        $outcome = is_string($values['outcome'] ?? null) ? trim($values['outcome']) : '';

        $note = new BrokerClientNote;
        $note->forceFill([
            'profile_id' => $profile->id,
            'broker_id' => $staff->broker_id,
            'author_user_id' => $actor->id,
            'type' => $values['type'],
            'outcome' => $outcome === '' ? null : $outcome,
            'body' => trim((string) $values['body']),
            'follow_up_on' => $values['follow_up_on'] ?? null,
        ])->save();

        return $note;
    }
}
