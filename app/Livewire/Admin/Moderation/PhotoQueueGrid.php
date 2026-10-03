<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Moderation;

use App\Actions\Moderation\ClaimModerationItem;
use App\Actions\Moderation\DecidePhoto;
use App\Domain\Media\PhotoUrls;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\RejectReason;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AdminUser;
use App\Models\Media;
use App\Models\ModerationItem;
use App\Queries\Moderation\ModerationQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A04 photo queue: a keyboard-driven grid (photo-moderation.js — arrows move, A approve, D reject,
 * Space reveal, Enter apply) of up to PAGE waiting photos, paid lane first. The batch is loaded —
 * and, for moderators, claimed with one UPDATE — on mount and after each apply (never in
 * render); photos held by someone else are left out. Photos are blurred until revealed. Each
 * decision is DecidePhoto (authorized, audited).
 */
#[Layout('layouts::admin', ['title' => 'Photo queue'])]
final class PhotoQueueGrid extends Component
{
    private const PAGE = 24;

    public string $reason = 'INAPPROPRIATE';

    public string $note = '';

    /** @var list<string> the item ids on screen (claimed by this moderator when they can act) */
    #[Locked]
    public array $batch = [];

    public function mount(ModerationQueue $queue, ClaimModerationItem $claim): void
    {
        Gate::authorize('moderation.view');
        $this->loadBatch($queue, $claim);
    }

    /**
     * Apply the marked decisions: item id => "approve" | "reject". Unknown ids, items that are no
     * longer waiting or are held by someone else are skipped and reported. Then the next batch
     * is loaded.
     *
     * @param  array<string, string>  $decisions
     */
    public function decide(array $decisions, DecidePhoto $decide, ModerationQueue $queue, ClaimModerationItem $claim): void
    {
        Gate::authorize('moderation.act');
        $reason = RejectReason::tryFrom($this->reason) ?? RejectReason::Inappropriate;
        $this->validate([
            'note' => ['nullable', 'string', 'max:1000',
                Rule::requiredIf($reason === RejectReason::Other && in_array('reject', $decisions, true))],
        ], ['note.required' => __('Write a note for the member when the reason is “Other”.')]);

        $done = 0;
        $skipped = 0;

        $items = ModerationItem::query()->ofType(ModerationItemType::Photo)->where('status', ModerationStatus::Open)
            ->whereKey(array_keys(array_slice($decisions, 0, self::PAGE, true)))->get()->keyBy('id');

        foreach ($decisions as $id => $verdict) {
            $item = $items->get($id);

            if ($item === null || ! in_array($verdict, ['approve', 'reject'], true)) {
                $skipped++;

                continue;
            }

            try {
                $decide->handle($this->admin(), $item, $verdict === 'approve', $reason, $this->note);
                $done++;
            } catch (ModerationItemUnavailable|AuthorizationException) {
                $skipped++;
            }
        }

        $this->note = '';
        $this->loadBatch($queue, $claim);
        $this->dispatch('photo-batch-applied');
        $this->dispatch('toast', type: $skipped > 0 ? 'error' : 'success',
            message: trans_choice(':count photo decided.|:count photos decided.', $done).($skipped > 0 ? ' '.__(':n skipped (taken or already decided).', ['n' => $skipped]) : ''));
    }

    /** Load (and claim) the next batch — e.g. after a while away, when claims may have lapsed. */
    public function refreshBatch(ModerationQueue $queue, ClaimModerationItem $claim): void
    {
        $this->loadBatch($queue, $claim);
    }

    public function render(): View
    {
        $admin = $this->admin();

        // Read only: the batch chosen in loadBatch(), minus anything decided since.
        /** @var Collection<int, ModerationItem> $items */
        $items = $this->batch === [] ? collect() : app(ModerationQueue::class)->open(ModerationItemType::Photo)
            ->whereKey($this->batch)
            ->with('profile:id,code,first_name,gender')
            ->get();

        $photos = Media::query()->whereIn('uuid', $items->pluck('subject_id')->filter()->all())->get()->keyBy('uuid');

        return view('livewire.admin.moderation.photo-queue-grid', [
            'items' => $items,
            'photos' => $photos,
            // Flags were computed at upload (UploadProfilePhoto) and stored on the item.
            'flags' => $items->mapWithKeys(fn (ModerationItem $item): array => [(string) $item->id => (array) ($item->fields['flags'] ?? [])]),
            'reasons' => RejectReason::options(),
            'placeholder' => PhotoUrls::PLACEHOLDER,
            'canDecide' => $admin->can('moderation.act'),
        ]);
    }

    private function loadBatch(ModerationQueue $queue, ClaimModerationItem $claim): void
    {
        $admin = $this->admin();

        $ids = $queue->open(ModerationItemType::Photo)
            ->where(fn ($q) => $q->whereNull('claimed_by_admin_id')->orWhere('claimed_until', '<', now())->orWhere('claimed_by_admin_id', $admin->id))
            ->limit(self::PAGE)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        // One UPDATE for the whole batch; anything taken meanwhile drops out.
        $this->batch = $admin->can('moderation.act') ? $claim->many($admin, $ids) : $ids;
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
