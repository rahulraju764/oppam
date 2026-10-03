<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members;

use App\Actions\Admin\Members\BulkMemberAction;
use App\Actions\Admin\Members\ExportMembers;
use App\Data\Admin\MemberSearchCriteria;
use App\Enums\Gender;
use App\Enums\MemberBulkAction;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Admin\MemberExportController;
use App\Models\AdminUser;
use App\Models\User;
use App\Queries\Admin\MemberSearchQuery;
use App\Services\Masters\Masters;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL as UrlGenerator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A03 member list: one search box (code / email / phone / name) + facets, all in the URL;
 * bulk suspend / reactivate / message with a typed reason (no bulk delete); audited CSV export
 * through a short-lived signed link. Page needs members.view; each action re-authorizes in its
 * Action.
 *
 * @property-read LengthAwarePaginator<int, User> $members
 */
#[Layout('layouts::admin', ['title' => 'Members'])]
final class Index extends Component
{
    use WithPagination;

    private const FILTERS = ['q', 'status', 'profileStatus', 'verified', 'plan', 'completeness', 'gender', 'religion', 'district', 'from', 'to', 'active', 'sort'];

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $profileStatus = '';

    #[Url(except: '')]
    public string $verified = '';

    #[Url(except: '')]
    public string $plan = '';

    #[Url(except: '')]
    public string $completeness = '';

    #[Url(except: '')]
    public string $gender = '';

    #[Url(except: '')]
    public string $religion = '';

    #[Url(except: '')]
    public string $district = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $active = '';

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    /** @var list<string> user ids ticked on this page (re-checked server-side by the Action) */
    public array $selected = [];

    public string $bulkAction = '';

    public string $reason = '';

    public string $bulkSubject = '';

    public string $bulkMessage = '';

    public function mount(): void
    {
        $this->authorize('members.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
        $this->selected = [];
    }

    /** @return LengthAwarePaginator<int, User> */
    #[Computed]
    public function members(): LengthAwarePaginator
    {
        return app(MemberSearchQuery::class)->build($this->criteria())->paginate(25);
    }

    /** Tick every member on the current page. */
    public function selectPage(): void
    {
        $this->selected = collect($this->members->items())->map(fn (User $member): string => (string) $member->id)->values()->all();
    }

    public function runBulk(BulkMemberAction $bulk): void
    {
        $this->validate([
            'bulkAction' => ['required', Rule::enum(MemberBulkAction::class)],   // no DELETE case exists
            'selected' => ['required', 'array', 'max:'.BulkMemberAction::MAX],
            'selected.*' => ['string'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['bulkAction.Illuminate\Validation\Rules\Enum' => __('Choose suspend, reactivate or send a message.')]);

        $action = MemberBulkAction::from($this->bulkAction);
        $this->authorize($action->permission());

        $result = $bulk->handle($this->admin(), $action, $this->selected, $this->reason, $this->bulkSubject, $this->bulkMessage);

        $this->reset('selected', 'bulkAction', 'reason', 'bulkSubject', 'bulkMessage');
        $this->dispatch('close-modal', name: 'bulk-action');
        $this->dispatch('toast', type: $result->skipped > 0 ? 'warning' : 'success', message: trans_choice(
            ':count member updated.|:count members updated.', $result->done,
        ).($result->skipped > 0 ? ' '.__(':n skipped (not in the right state, or no verified email).', ['n' => $result->skipped]) : ''));
        unset($this->members);
    }

    /**
     * Start the CSV download. The filters (the search box may hold a phone or email) stay on the
     * server under a single-use token for this admin; the 5-minute signed link carries only it.
     */
    public function export(ExportMembers $export): mixed
    {
        try {
            $export->authorize($this->admin(), $this->criteria());   // refusal: logged + audited
        } catch (AuthorizationException) {
            abort(403);
        }

        $token = Str::random(40);
        Cache::put(MemberExportController::CACHE_PREFIX.$token, ['admin' => $this->admin()->id, 'filters' => $this->filters()], now()->addMinutes(5));

        return $this->redirect(UrlGenerator::temporarySignedRoute('admin.members.export', now()->addMinutes(5), ['token' => $token]));
    }

    public function render(Masters $masters): View
    {
        return view('livewire.admin.members.index', [
            'statuses' => UserStatus::options(),
            'profileStatuses' => ProfileStatus::options(),
            'plans' => PlanCode::options(),
            'genders' => Gender::options(),
            'religions' => Masters::forSelect($masters->religions()),
            'districts' => Masters::forSelect($masters->allDistricts()),
            'bulkActions' => MemberBulkAction::options(),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function criteria(): MemberSearchCriteria
    {
        return MemberSearchCriteria::fromInput($this->filters());
    }

    /** @return array<string, string> */
    private function filters(): array
    {
        return collect(self::FILTERS)->mapWithKeys(fn (string $f): array => [$f => (string) $this->{$f}])->all();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
