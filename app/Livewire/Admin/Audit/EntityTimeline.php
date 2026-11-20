<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Audit;

use App\Domain\Audit\TimelineSubjects;
use App\Enums\TimelineEntity;
use App\Enums\UserRole;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Audit\AuditLogQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A12 per-entity timeline (P9.2a; PRD A12 "viewer with filters and per-entity timeline"), mounted
 * lazily on the member (A03), bureau (A07) and order (A06) detail pages: the audit rows about the
 * entity and the records it owns (TimelineSubjects), newest first. Needs the host page's
 * permission (TimelineEntity::permission); the entity is found by its code (#[Locked]), never a
 * ULID — an unknown code is a 404. Admins with `audit.view` get a link into the full viewer.
 */
final class EntityTimeline extends Component
{
    use WithPagination;

    #[Locked]
    public string $entity = '';

    #[Locked]
    public string $code = '';

    /** @var array<string, list<string>>|null resolved once per request */
    private ?array $resolved = null;

    public function mount(string $entity, string $code): void
    {
        $kind = TimelineEntity::tryFrom($entity) ?? abort(404);
        $this->authorize($kind->permission());
        $this->entity = $kind->value;
        $this->code = strtoupper($code);
        $this->subjects();   // 404 for an unknown code
    }

    public function placeholder(): View
    {
        return view('livewire.admin.members.tabs.placeholder');
    }

    public function render(AuditLogQuery $audit): View
    {
        $this->authorize($this->kind()->permission());
        $subjects = $this->subjects();

        $entries = AuditLog::query()
            ->where(function (Builder $query) use ($subjects): void {
                foreach ($subjects as $type => $ids) {
                    $query->orWhere(fn (Builder $q) => $q->where('subject_type', $type)->whereIn('subject_id', $ids));
                }
            })
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25, pageName: 'timeline');

        $admin = auth('admin')->user();

        return view('livewire.admin.audit.entity-timeline', [
            'entries' => $entries,
            'names' => $audit->actorNames($entries->items()),
            'viewerLink' => $admin instanceof AdminUser && Gate::forUser($admin)->allows('audit.view'),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function kind(): TimelineEntity
    {
        return TimelineEntity::from($this->entity);
    }

    /** @return array<string, list<string>> */
    private function subjects(): array
    {
        $subjects = app(TimelineSubjects::class);

        return $this->resolved ??= match ($this->kind()) {
            TimelineEntity::Member => (function () use ($subjects): array {
                $profile = Profile::withTrashed()->where('code', $this->code)->first() ?? abort(404);
                $member = User::withTrashed()->whereKey($profile->user_id)->where('role', UserRole::Member->value)->first() ?? abort(404);

                return $subjects->forMember($member, $profile);
            })(),
            TimelineEntity::Broker => $subjects->forBroker(Broker::query()->where('code', $this->code)->first() ?? abort(404)),
            TimelineEntity::Order => $subjects->forOrder(Order::query()->where('code', $this->code)->first() ?? abort(404)),
        };
    }
}
