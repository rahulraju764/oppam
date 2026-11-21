<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Audit;

use App\Actions\Audit\ExportAuditLog;
use App\Data\Audit\AuditFilterData;
use App\Domain\Audit\AuditDiff;
use App\Domain\Audit\TimelineSubjects;
use App\Http\Controllers\Admin\AuditExportController;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Queries\Audit\AuditLogQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL as SignedUrl;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * A12 audit log viewer (P9.2a; PRD A12 "viewer with filters", `audit.view`): the whole trail,
 * newest first, cursor-paged, with the filters in the URL (AuditLogQuery) and a drawer showing a
 * row's redacted before / after diff, reason, IP and browser. Read-only — nothing here edits or
 * deletes, and the model and the database refuse both. CSV export needs `audit.export`.
 */
#[Layout('layouts::admin', ['title' => 'Audit log'])]
final class AuditLogIndex extends Component
{
    use WithPagination;

    public const FILTERS = ['actor', 'action', 'subjectType', 'subject', 'from', 'to', 'search', 'entity'];

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $subjectType = '';

    #[Url(except: '')]
    public string $subject = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $search = '';

    /** "member:OPM12370" etc. — set by the timeline's "Open in the audit log" link. */
    #[Url(except: '')]
    public string $entity = '';

    /** The row open in the drawer ('' = none). Any row is readable with audit.view; the id is format-checked. */
    public string $entry = '';

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage('after');
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage('after');
    }

    public function show(string $id): void
    {
        $this->authorize('audit.view');
        $this->entry = preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id) === 1 ? $id : '';
        if ($this->entry !== '') {
            $this->dispatch('open-drawer', name: 'audit-entry');
        }
    }

    public function export(ExportAuditLog $export): mixed
    {
        $filters = $this->filters();

        try {
            $rows = $export->prepare($this->admin(), $filters);
        } catch (AuthorizationException|TooManyRequestsHttpException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return null;
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: (string) collect($e->errors())->flatten()->first());

            return null;
        }

        $token = Str::random(40);
        Cache::put(AuditExportController::CACHE_PREFIX.$token, [
            'admin' => $this->admin()->id, 'filters' => $filters->toArray(), 'rows' => $rows,
        ], now()->addMinutes(5));

        return $this->redirect(SignedUrl::temporarySignedRoute('admin.audit.export', now()->addMinutes(5), ['token' => $token]));
    }

    public function render(AuditLogQuery $audit, AuditDiff $diff): View
    {
        $this->authorize('audit.view');
        $result = $audit->page($this->filters());
        $open = $this->entry !== '' ? AuditLog::query()->find($this->entry) : null;

        return view('livewire.admin.audit.audit-log-index', [
            'rows' => $result['rows'],
            'unknown' => $result['unknown'],
            'ignoredTerms' => $result['ignoredTerms'],
            'timelineCapped' => $result['capped'],
            'cap' => TimelineSubjects::CAP,
            'names' => $audit->actorNames([...$result['rows']->items(), ...($open !== null ? [$open] : [])]),
            'areas' => array_combine($audit->areas(), $audit->areas()),
            'subjectTypes' => array_combine($audit->subjectTypes(), $audit->subjectTypes()),
            'open' => $open,
            'lines' => $open !== null ? $diff->lines($open) : [],
            'canExport' => Gate::forUser($this->admin())->allows('audit.export'),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function filters(): AuditFilterData
    {
        return AuditFilterData::fromArray([
            'actor' => $this->actor, 'action' => $this->action, 'subjectType' => $this->subjectType,
            'subject' => $this->subject, 'from' => $this->from, 'to' => $this->to, 'search' => $this->search,
            'entity' => $this->entity,
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }
}
