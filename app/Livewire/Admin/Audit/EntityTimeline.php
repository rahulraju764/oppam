<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Audit;

use App\Domain\Audit\TimelineSubjects;
use App\Enums\TimelineEntity;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Queries\Audit\AuditLogQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A12 per-entity timeline (P9.2a; PRD A12 "viewer with filters and per-entity timeline"), mounted
 * lazily on the member (A03), bureau (A07) and order (A06) detail pages: the audit rows about the
 * entity and the records it owns (TimelineSubjects), newest first. Needs the host page's
 * permission (TimelineEntity::permission); the entity is found by its code (#[Locked]), never a
 * ULID — an unknown code is a 404. Admins with `audit.view` get a link into the full viewer,
 * filtered to this timeline (the viewer's `entity` filter). When an owned-record list hits
 * TimelineSubjects::CAP the screen says older records are left out.
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

        $query = AuditLog::query();
        TimelineSubjects::scope($query, $this->subjects());
        $entries = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25, pageName: 'timeline');

        $admin = auth('admin')->user();

        return view('livewire.admin.audit.entity-timeline', [
            'entries' => $entries,
            'names' => $audit->actorNames($entries->items()),
            'viewerLink' => $admin instanceof AdminUser && Gate::forUser($admin)->allows('audit.view'),
            'entityFilter' => $this->entity.':'.$this->code,
            'capped' => TimelineSubjects::capped($this->subjects()),
            'cap' => TimelineSubjects::CAP,
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
        return $this->resolved ??= app(TimelineSubjects::class)->resolve($this->kind(), $this->code) ?? abort(404);
    }
}
