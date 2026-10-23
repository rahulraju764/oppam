<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Safety;

use App\Actions\Admin\Safety\DecideAbuseCase;
use App\Actions\Admin\Safety\RevealCaseEvidence;
use App\Actions\Admin\Safety\SetLegalHold;
use App\Actions\Admin\Safety\TriageAbuseCase;
use App\Enums\CaseOutcome;
use App\Enums\CasePriority;
use App\Exceptions\Admin\MemberStateConflict;
use App\Exceptions\Safety\CaseUnavailable;
use App\Models\AbuseCase;
use App\Models\AdminUser;
use App\Models\Block;
use App\Models\Media;
use App\Models\Profile;
use App\Models\Report;
use App\Models\SafetyAction;
use App\Queries\Safety\CaseEvidence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Review one A10 abuse case (PRD A10 "case review with profile, photos, history, conversation
 * evidence behind an audited 'reveal evidence' click"; F07). Shows the member, their photos, every
 * report in the case (reason, details, reporter code), their safety history, earlier cases and a
 * read-only block investigation (counts + who, by code). Message evidence appears only after the
 * audited reveal (RevealCaseEvidence). Decisions (DecideAbuseCase), triage (TriageAbuseCase) and
 * the legal hold (SetLegalHold, super admin) each re-check their permission on the server.
 * `revealed` is locked: it can't be set from the browser.
 */
#[Layout('layouts::admin', ['title' => 'Safety case'])]
final class CaseReview extends Component
{
    #[Locked]
    public string $caseCode = '';

    #[Locked]
    public bool $revealed = false;

    public string $note = '';

    public string $priority = '';

    public string $priorityReason = '';

    public string $holdReason = '';

    /** @var list<string> CONTENT_REMOVAL: photo uuids to reject */
    public array $photos = [];

    public function mount(string $case): void
    {
        Gate::authorize('safety.reports.view');
        $this->caseCode = $this->case($case)->code;
        $this->priority = $this->case()->priority->value;
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return ['echo-private:admin.queues,.queue.count' => '$refresh'];
    }

    public function reveal(RevealCaseEvidence $reveal): void
    {
        try {
            $reveal->handle($this->admin(), $this->case());
        } catch (AuthorizationException) {
            $this->denied();

            return;
        }

        $this->revealed = true;
    }

    public function decide(string $outcome, DecideAbuseCase $decide): void
    {
        $choice = CaseOutcome::tryFrom($outcome) ?? abort(404);

        try {
            $decide->handle($this->admin(), $this->case(), $choice, $this->note, array_values(array_filter($this->photos, 'is_string')));
        } catch (AuthorizationException) {
            $this->denied();

            return;
        } catch (CaseUnavailable|MemberStateConflict $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->reset('note', 'photos');
        foreach (['decide-hide', 'decide-suspend', 'decide-ban'] as $modal) {
            $this->dispatch('close-modal', name: $modal);
        }
        $this->dispatch('toast', type: 'success', title: __('Case closed: :outcome.', ['outcome' => $choice->label()]));
    }

    public function assignToMe(TriageAbuseCase $triage): void
    {
        try {
            $triage->assignToMe($this->admin(), $this->case());
        } catch (AuthorizationException) {
            $this->denied();

            return;
        } catch (CaseUnavailable $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', title: __('The case is assigned to you.'));
    }

    public function changePriority(TriageAbuseCase $triage): void
    {
        $priority = CasePriority::tryFrom($this->priority) ?? abort(404);

        try {
            $triage->changePriority($this->admin(), $this->case(), $priority, $this->priorityReason);
        } catch (AuthorizationException) {
            $this->denied();

            return;
        } catch (CaseUnavailable $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->reset('priorityReason');
        $this->dispatch('close-modal', name: 'case-priority');
        $this->dispatch('toast', type: 'success', title: __('Priority changed to :p.', ['p' => $priority->value]));
    }

    public function setHold(bool $hold, SetLegalHold $set): void
    {
        try {
            $set->handle($this->admin(), $this->case(), $hold, $this->holdReason);
        } catch (AuthorizationException) {
            $this->denied();

            return;
        } catch (ValidationException $invalid) {
            throw ValidationException::withMessages(['holdReason' => $invalid->errors()['reason'] ?? [__('Give a reason.')]]);
        }

        $this->reset('holdReason');
        $this->dispatch('close-modal', name: 'legal-hold');
        $this->dispatch('toast', type: 'success', title: $hold ? __('Legal hold placed.') : __('Legal hold lifted.'));
    }

    public function render(CaseEvidence $evidence): View
    {
        $case = $this->case();
        $case->load(['assignee:id,name', 'decidedBy:id,name']);
        $admin = $this->admin();
        $profile = Profile::withTrashed()->with('user:id,status,created_at')->find($case->reported_profile_id);
        $act = $case->isOpen() && $admin->can('safety.reports.act');
        $reports = Report::query()->where('abuse_case_id', $case->id)->with(['reporter:id,code', 'messages:id'])->orderBy('created_at')->get();

        return view('livewire.admin.safety.case-review', [
            'case' => $case,
            'profile' => $profile,
            'photoList' => $profile === null ? collect() : Media::query()->where('model_type', $profile->getMorphClass())->where('model_id', $profile->id)
                ->where('collection_name', Profile::PHOTOS)->orderBy('order_column')->get(),
            'reports' => $reports,
            // A decision waits for the audited reveal only when there is chat evidence to look at.
            'hasChatEvidence' => $reports->contains(fn (Report $r): bool => $r->messages->isNotEmpty()),
            'history' => SafetyAction::query()->where('profile_id', $case->reported_profile_id)->with('admin:id,name')->latest('created_at')->limit(20)->get(),
            'earlierCases' => AbuseCase::query()->where('reported_profile_id', $case->reported_profile_id)->whereKeyNot($case->id)->latest()->limit(10)
                ->get(['id', 'code', 'status', 'outcome', 'created_at', 'decided_at']),
            'blockedBy' => Block::query()->where('blocked_profile_id', $case->reported_profile_id)->count(),
            'blocking' => Block::query()->where('blocker_profile_id', $case->reported_profile_id)->count(),
            'recentBlockers' => Block::query()->where('blocked_profile_id', $case->reported_profile_id)->with('blocker:id,code')->latest()->limit(10)->get(),
            'evidence' => $this->revealed ? $evidence->for($case) : [],   // list<CaseEvidenceData>, only after the audited reveal
            'outcomes' => $act ? collect(CaseOutcome::cases())
                ->filter(fn (CaseOutcome $o): bool => $o->extraPermission() === null || $admin->can($o->extraPermission()))->values()->all() : [],
            'canTriage' => $act,
            'priorities' => CasePriority::options(),
            'isSuperAdmin' => $admin->isSuperAdmin(),
            'evidencePackUrl' => $admin->isSuperAdmin() && $profile?->legal_hold_at !== null
                ? URL::temporarySignedRoute('admin.safety.cases.evidence-pack', now()->addMinutes(5), ['case' => $case->code]) : null,
            'memberUrl' => $profile !== null && $admin->can('members.view') ? route('admin.members.show', ['profile' => $profile->code]) : null,
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function case(?string $code = null): AbuseCase
    {
        $code ??= $this->caseCode;

        return preg_match('/^CASE[0-9]+$/', $code) === 1 ? (AbuseCase::query()->where('code', $code)->first() ?? abort(404)) : abort(404);
    }

    private function denied(): void
    {
        $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
