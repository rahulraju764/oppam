<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Contracts\CaptchaVerifier;
use App\Data\Support\ContactRequestData;
use App\Enums\ContactStatus;
use App\Enums\SettingKey;
use App\Enums\TicketCategory;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\ContactSubmission;
use App\Models\User;
use App\Notifications\Support\ContactReceived;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The public contact form (M12, owner 2026-11-02: inbox first). In order: honeypot (a bot gets the
 * same thank-you and nothing is stored), validation, the human check (Turnstile), the rate limit
 * `contact.max_per_hour` per member or IP, then the message is stored in the A08 inbox and support
 * is emailed. Likely spam (many links, the same text again from the same address) is stored in the
 * spam tab instead and nobody is emailed. The IP is kept only as a salted hash.
 */
final class SubmitContact
{
    /** This many links or more marks a message as spam. */
    public const SPAM_LINKS = 3;

    public function __construct(
        private readonly CaptchaVerifier $captcha,
        private readonly RateLimiter $limiter,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @return ContactSubmission|null null for a bot caught by the honeypot
     *
     * @throws ValidationException
     * @throws SafetyRefused too many messages this hour
     * @throws ImpersonationRestricted
     */
    public function handle(?User $member, ContactRequestData $data, string $ip): ?ContactSubmission
    {
        if ($member !== null) {
            $this->impersonation->assertAllowed();
        }
        if (trim($data->honeypot) !== '') {
            return null;
        }

        $valid = Validator::make([
            'name' => trim($data->name),
            'email' => Str::lower(trim($data->email)),
            'category' => $data->category,
            'message' => trim($data->message),
        ], [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:191'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [], [
            'name' => __('name'), 'email' => __('email'), 'category' => __('topic'), 'message' => __('message'),
        ])->validate();

        if (! $this->captcha->verify($data->captchaToken, $ip)) {
            throw ValidationException::withMessages(['captcha' => __('Please complete the check that you’re not a robot, then send again.')]);
        }

        $key = 'contact:'.($member !== null ? 'u:'.$member->id : 'ip:'.$ip);
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::ContactMaxPerHour))) {
            throw SafetyRefused::tooMany();
        }
        $this->limiter->hit($key, 3600);

        $ipHash = hash_hmac('sha256', $ip, (string) config('app.key'));
        $spam = $this->spamReason($valid['message'], $ipHash);

        return DB::transaction(function () use ($member, $valid, $ipHash, $spam): ContactSubmission {
            $submission = new ContactSubmission;
            $submission->forceFill([
                'code' => $this->code(),
                'user_id' => $member?->id,
                'name' => $valid['name'],
                'email' => $valid['email'],
                'category' => TicketCategory::from($valid['category']),
                'message' => $valid['message'],
                'status' => $spam === null ? ContactStatus::New : ContactStatus::Spam,
                'spam_reason' => $spam,
                'ip_hash' => $ipHash,
            ])->save();

            $support = trim(Settings::string(SettingKey::SiteSupportEmail));
            if ($spam === null && $support !== '') {
                DB::afterCommit(fn () => Notification::route('mail', $support)
                    ->notify(new ContactReceived($submission->code, $submission->category->label())));
            }

            return $submission;
        });
    }

    private function spamReason(string $message, string $ipHash): ?string
    {
        if (preg_match_all('~https?://|www\.~i', $message) >= self::SPAM_LINKS) {
            return 'links';
        }

        $repeat = ContactSubmission::query()->where('ip_hash', $ipHash)
            ->where('created_at', '>=', now()->subDay())
            ->where('message', $message)->exists();

        return $repeat ? 'repeat' : null;
    }

    private function code(): string
    {
        do {
            $code = 'CNT'.Str::upper(Str::random(8));
        } while (ContactSubmission::query()->where('code', $code)->exists());

        return $code;
    }
}
