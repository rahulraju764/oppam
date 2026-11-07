<?php

declare(strict_types=1);

namespace App\Jobs\Support;

use App\Enums\ContactStatus;
use App\Enums\SettingKey;
use App\Models\ContactSubmission;
use App\Support\Facades\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Contact-form messages are personal data with no use once handled (P8.1b): spam goes after
 * `contact.spam_retention_days` (30), everything else after `contact.retention_days` (365). A
 * message converted to a ticket lives on in the ticket. Batched; replies go with their message.
 */
final class PruneContactSubmissions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const BATCH = 1000;

    public int $timeout = 600;

    public function handle(): void
    {
        $this->prune(ContactSubmission::query()->where('status', ContactStatus::Spam)
            ->where('created_at', '<', now()->subDays(Settings::int(SettingKey::ContactSpamRetentionDays))));

        $this->prune(ContactSubmission::query()
            ->where('created_at', '<', now()->subDays(Settings::int(SettingKey::ContactRetentionDays))));
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<ContactSubmission>  $query */
    private function prune($query): void
    {
        do {
            $ids = (clone $query)->limit(self::BATCH)->pluck('id');
            $deleted = $ids->isEmpty() ? 0 : ContactSubmission::query()->whereIn('id', $ids)->delete();
        } while ($deleted === self::BATCH);
    }
}
