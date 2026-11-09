<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Data\Comms\AnnouncementData;
use App\Domain\Comms\ActiveAnnouncements;
use App\Events\Comms\AnnouncementPublished;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\Announcement;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A14 announcements (P8.3a, `notifications.broadcast`): create or change a member banner.
 *  - the button link is a path on this site or an https:// address (never javascript:, data:, http:);
 *  - the end is in the future and after the start;
 *  - live now → open member pages get it at once (AnnouncementPublished, after commit);
 *    scheduled → PublishDueAnnouncements sends it when its start comes (once: broadcast_at);
 *  - an ended announcement can't be changed. Audited (title / audience, not the text).
 */
final class SaveAnnouncement
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActiveAnnouncements $active,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws CommsRefused
     */
    public function handle(AdminUser $admin, AnnouncementData $data, ?Announcement $existing = null): Announcement
    {
        Gate::forUser($admin)->authorize('notifications.broadcast');
        $this->validate($data);

        $announcement = DB::transaction(function () use ($admin, $data, $existing): Announcement {
            $row = $existing !== null ? Announcement::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail() : new Announcement;
            if ($row->exists && $row->ended_at !== null) {
                throw CommsRefused::announcementEnded();
            }

            $isNew = ! $row->exists;
            $row->forceFill([
                'code' => $row->code ?? $this->newCode(),
                'title' => trim($data->title),
                'body' => trim($data->body),
                'action_url' => self::blankToNull($data->actionUrl),
                'action_text' => self::blankToNull($data->actionText),
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
                'audience' => $data->audience,
                'is_dismissible' => $data->isDismissible,
                'created_by' => $row->created_by ?? $admin->id,
            ]);
            // Live now → sent now; scheduled (or rescheduled) → the due job sends it at its start.
            $row->broadcast_at = $row->isLive() ? now()->toImmutable() : null;
            $row->save();

            // The cached list is dropped before the (after-commit) live signal, so banners re-read fresh data.
            DB::afterCommit(fn () => $this->active->forget());
            if ($row->isLive()) {
                AnnouncementPublished::dispatch($row->code);
            }

            $this->audit->record($isNew ? 'comms.announcement_created' : 'comms.announcement_updated', $row,
                after: ['title' => $row->title, 'audience' => $row->audience->value, 'live' => $row->isLive()],
                actor: $admin, subjectLabel: $row->code);

            return $row;
        });

        $this->active->forget();

        return $announcement;
    }

    public static function safeLink(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return preg_match('#^/[A-Za-z0-9/_\-?=&\#.%]*$#', $url) === 1;
        }

        return str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false && mb_strlen($url) <= 255;
    }

    /** @throws ValidationException */
    private function validate(AnnouncementData $data): void
    {
        $errors = [];
        $title = trim($data->title);
        $body = trim($data->body);
        $url = (string) self::blankToNull($data->actionUrl);
        $text = (string) self::blankToNull($data->actionText);

        if ($title === '' || mb_strlen($title) > 150) {
            $errors['title'] = __('Give it a title (up to 150 characters).');
        }
        if ($body === '' || mb_strlen($body) > 500) {
            $errors['body'] = __('Write the message (up to 500 characters).');
        }
        if ($url !== '' && ! self::safeLink($url)) {
            $errors['actionUrl'] = __('Use a page on this site (starting with /) or an https:// address.');
        }
        if (($url === '') !== ($text === '') || mb_strlen($text) > 50) {
            $errors['actionText'] = __('A button needs both a link and its text (up to 50 characters).');
        }
        if ($data->endsAt !== null && ($data->endsAt->isPast() || ($data->startsAt !== null && ! $data->endsAt->gt($data->startsAt)))) {
            $errors['endsAt'] = __('The end must be in the future and after the start.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }

    private function newCode(): string
    {
        do {
            $code = 'ANN'.Str::upper(Str::random(8));
        } while (Announcement::query()->where('code', $code)->exists());

        return $code;
    }
}
