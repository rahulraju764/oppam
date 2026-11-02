<?php

declare(strict_types=1);

namespace App\Actions\Admin\Content;

use App\Enums\ContentStatus;
use App\Exceptions\Admin\ContentRefused;
use App\Models\AdminUser;
use App\Models\EditorialContent;
use App\Models\SuccessStory;
use App\Queries\Content\HomeCounters;
use App\Services\Audit\AuditLogger;
use App\Services\Content\ContentImageStore;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A08 workflow moves, under the row's lock. `content.edit` moves work between draft and review;
 * `content.publish` schedules, publishes, releases waiting edits and archives. Every step that puts
 * something on the site re-checks publishBlocker() (consent, image), and the scheduled job does too.
 */
final class TransitionContent
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContentImageStore $images,
        private readonly HomeCounters $counters,
    ) {}

    public function submit(AdminUser $admin, EditorialContent $row): EditorialContent
    {
        Gate::forUser($admin)->authorize('content.edit');

        return $this->move($admin, $row, ContentStatus::InReview, 'content.submitted');
    }

    public function backToDraft(AdminUser $admin, EditorialContent $row): EditorialContent
    {
        Gate::forUser($admin)->authorize('content.edit');

        return $this->move($admin, $row, ContentStatus::Draft, 'content.back_to_draft', function (EditorialContent $locked): void {
            $locked->forceFill(['scheduled_at' => null, 'archived_at' => null]);
            if ($locked instanceof SuccessStory) {
                // Back to work, but withdrawal already cleared its consent and photo: both must come again.
                $locked->forceFill(['withdrawn_at' => null, 'withdrawn_reason' => null]);
            }
        });
    }

    /** @throws AuthorizationException|ContentRefused */
    public function schedule(AdminUser $admin, EditorialContent $row, CarbonImmutable $at, ?int $expectedVersion = null): EditorialContent
    {
        Gate::forUser($admin)->authorize('content.publish');

        if ($at->isPast()) {
            throw ContentRefused::scheduleInPast();
        }

        return $this->move($admin, $row, ContentStatus::Scheduled, 'content.scheduled', function (EditorialContent $locked) use ($at, $expectedVersion): void {
            $this->assertVersion($locked, $expectedVersion);
            $this->assertPublishable($locked);
            $locked->forceFill(['scheduled_at' => $at->utc()]);
        });
    }

    /** @throws AuthorizationException|ContentRefused */
    public function publish(AdminUser $admin, EditorialContent $row, ?int $expectedVersion = null): EditorialContent
    {
        Gate::forUser($admin)->authorize('content.publish');

        return $this->move($admin, $row, ContentStatus::Published, 'content.published', function (EditorialContent $locked) use ($admin, $expectedVersion): void {
            $this->assertVersion($locked, $expectedVersion);
            $this->assertPublishable($locked);
            $locked->forceFill(['scheduled_at' => null, 'published_at' => now(), 'published_by_admin_id' => $admin->id]);
        });
    }

    /**
     * The scheduled job: only a row still SCHEDULED and due. One that can no longer go live (consent
     * removed) returns to review with an audit row; it is never published and never fails the run.
     */
    public function publishDue(EditorialContent $row): void
    {
        DB::transaction(function () use ($row): void {
            $locked = $row->newQuery()->whereKey($row->id)->lockForUpdate()->first();
            if (! $locked instanceof EditorialContent || $locked->status !== ContentStatus::Scheduled || $locked->scheduled_at === null || $locked->scheduled_at->isFuture()) {
                return;
            }

            $blocker = $locked->publishBlocker();
            if ($blocker !== null) {
                $locked->forceFill(['status' => ContentStatus::InReview, 'scheduled_at' => null])->save();
                $this->audit->record('content.schedule_refused', $locked, ['status' => ContentStatus::Scheduled->value], ['status' => ContentStatus::InReview->value], $blocker, subjectLabel: $locked->code);

                return;
            }

            $locked->forceFill(['status' => ContentStatus::Published, 'scheduled_at' => null, 'published_at' => now()])->save();
            $this->audit->record('content.published', $locked, ['status' => ContentStatus::Scheduled->value], ['status' => ContentStatus::Published->value], subjectLabel: $locked->code);
            DB::afterCommit(fn () => $this->counters->forget());
        });
    }

    /** A live row's waiting edits go on the site. */
    public function publishChanges(AdminUser $admin, EditorialContent $row, ?int $expectedVersion = null): EditorialContent
    {
        Gate::forUser($admin)->authorize('content.publish');

        return DB::transaction(function () use ($admin, $row, $expectedVersion): EditorialContent {
            $locked = $this->locked($row);
            if (! $locked->isLive() || ! $locked->hasPendingChanges()) {
                throw ContentRefused::noPendingChanges();
            }
            $this->assertVersion($locked, $expectedVersion);
            $this->assertPublishable($locked);

            $replaced = $this->replacedImage($locked);
            $fields = array_keys($locked->draft ?? []);
            $locked->forceFill($locked->draft ?? [])->forceFill(['draft' => null, 'published_at' => now(), 'published_by_admin_id' => $admin->id])->save();
            $this->audit->record('content.changes_published', $locked, [], ['fields' => $fields], actor: $admin, subjectLabel: $locked->code);
            DB::afterCommit(fn () => $this->images->delete($replaced));

            return $locked;
        });
    }

    public function discardChanges(AdminUser $admin, EditorialContent $row): EditorialContent
    {
        Gate::forUser($admin)->authorize('content.edit');

        return DB::transaction(function () use ($admin, $row): EditorialContent {
            $locked = $this->locked($row);
            if (! $locked->hasPendingChanges()) {
                throw ContentRefused::noPendingChanges();
            }

            $unused = $locked->draft['image_path'] ?? null;
            $locked->forceFill(['draft' => null])->save();
            $this->audit->record('content.changes_discarded', $locked, actor: $admin, subjectLabel: $locked->code);
            DB::afterCommit(fn () => $this->images->delete(is_string($unused) ? $unused : null));

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException no reason
     * @throws ContentRefused
     */
    public function archive(AdminUser $admin, EditorialContent $row, string $reason): EditorialContent
    {
        Gate::forUser($admin)->authorize('content.publish');
        $reason = ReasonRule::check($reason);

        return $this->move($admin, $row, ContentStatus::Archived, 'content.archived', function (EditorialContent $locked): void {
            $unused = $locked->draft['image_path'] ?? null;
            $locked->forceFill(['draft' => null, 'scheduled_at' => null, 'archived_at' => now()]);
            DB::afterCommit(fn () => $this->images->delete(is_string($unused) ? $unused : null));
        }, $reason);
    }

    /** @param  (Closure(EditorialContent): void)|null  $apply */
    private function move(AdminUser $admin, EditorialContent $row, ContentStatus $to, string $action, ?Closure $apply = null, ?string $reason = null): EditorialContent
    {
        return DB::transaction(function () use ($admin, $row, $to, $action, $apply, $reason): EditorialContent {
            $locked = $this->locked($row);
            $from = $locked->status;
            if (! $from->canMoveTo($to)) {
                throw ContentRefused::notAllowedFrom($from->label());
            }

            if ($apply !== null) {
                $apply($locked);
            }
            $locked->forceFill(['status' => $to])->save();
            $this->audit->record($action, $locked, ['status' => $from->value], ['status' => $to->value], $reason, actor: $admin, subjectLabel: $locked->code);
            DB::afterCommit(fn () => $this->counters->forget());

            return $locked;
        });
    }

    private function locked(EditorialContent $row): EditorialContent
    {
        /** @var EditorialContent */
        return $row->newQuery()->whereKey($row->id)->lockForUpdate()->firstOrFail();
    }

    /** What the publisher was looking at must still be what goes out. @throws ContentRefused */
    private function assertVersion(EditorialContent $row, ?int $expected): void
    {
        if ($expected !== null && $row->lock_version !== $expected) {
            throw ContentRefused::changedMeanwhile();
        }
    }

    /** @throws ContentRefused */
    private function assertPublishable(EditorialContent $row): void
    {
        $blocker = $row->publishBlocker();
        if ($blocker !== null) {
            throw ContentRefused::blocked($blocker);
        }
    }

    /** The published image a waiting replacement takes over from; removed once it's live. */
    private function replacedImage(EditorialContent $row): ?string
    {
        $next = $row->draft['image_path'] ?? null;

        return is_string($next) && $next !== $row->image_path ? $row->image_path : null;
    }
}
