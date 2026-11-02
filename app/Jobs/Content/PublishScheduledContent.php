<?php

declare(strict_types=1);

namespace App\Jobs\Content;

use App\Actions\Admin\Content\TransitionContent;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A08: puts SCHEDULED rows on the site when their time comes. Safe to re-run; TransitionContent re-checks each row. */
final class PublishScheduledContent implements ShouldQueue
{
    use Queueable;

    public function handle(TransitionContent $transition): void
    {
        foreach (ContentType::cases() as $type) {
            $type->model()::query()
                ->where('status', ContentStatus::Scheduled)
                ->where('scheduled_at', '<=', now())
                ->orderBy('id')
                ->each(fn ($row) => $transition->publishDue($row));
        }
    }
}
