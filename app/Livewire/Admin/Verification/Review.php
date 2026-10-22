<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Verification;

use App\Actions\Admin\Verification\ApproveVerification;
use App\Actions\Admin\Verification\ClaimVerificationRequest;
use App\Actions\Admin\Verification\EscalateVerification;
use App\Actions\Admin\Verification\OpenVerificationDocuments;
use App\Actions\Admin\Verification\RejectVerification;
use App\Domain\Verification\DocumentViewerAccess;
use App\Enums\VerificationFlag;
use App\Enums\VerificationRejectReason;
use App\Enums\VerificationStatus;
use App\Exceptions\Verification\VerificationRequestUnavailable;
use App\Models\AdminUser;
use App\Models\VerificationRequest;
use App\Queries\Billing\CurrentMembership;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A05 review (/verification/{code}): the member's latest ID request — member summary, masked
 * number, the name as printed, the automatic fraud flags, earlier requests, Malayali
 * naming-variant guidance — and the decisions: approve, reject (reason → member guidance + note),
 * escalate (internal note), skip. Opening a waiting request claims it; if another officer holds it
 * the page is read-only. Documents open only on demand ("View documents", needs
 * `verification.document.view`) in the in-browser viewer: 10-minute links bound to this session,
 * watermarked, no download, every fetch audited.
 */
#[Layout('layouts::admin', ['title' => 'ID review'])]
final class Review extends Component
{
    #[Locked]
    public string $code = '';

    #[Locked]
    public string $requestId = '';

    /** @var array<string, string> side slug => signed URL, set only by viewDocuments() */
    #[Locked]
    public array $documentUrls = [];

    #[Locked]
    public int $viewerUntil = 0;

    public ?string $claimedBy = null;

    public string $reason = '';

    public string $note = '';

    public string $escalationNote = '';

    public function mount(string $profile, ClaimVerificationRequest $claim): void
    {
        Gate::authorize('verification.queue.view');

        $request = VerificationRequest::query()
            ->whereHas('profile', fn ($q) => $q->withTrashed()->where('code', $profile))
            ->latest('submitted_at')->latest('id')
            ->first() ?? abort(404);

        $this->code = $profile;
        $this->requestId = $request->id;
        $admin = $this->admin();

        if ($request->status->isOpen() && $admin->can('verification.approve')
            && ($request->status !== VerificationStatus::Escalated || $admin->isSuperAdmin())) {
            try {
                $claim->handle($admin, $request);
            } catch (VerificationRequestUnavailable) {
                $this->claimedBy = $request->claimedBy?->name;
            }
        }
    }

    public function approve(ApproveVerification $approve): mixed
    {
        return $this->decide(fn () => $approve->handle($this->admin(), $this->request()), __('ID of :code approved — the Verified badge is on.', ['code' => $this->code]));
    }

    public function reject(RejectVerification $reject): mixed
    {
        $this->validate(['reason' => ['required', 'in:'.implode(',', array_keys(VerificationRejectReason::options()))]], [], ['reason' => __('reason')]);

        return $this->decide(fn () => $reject->handle($this->admin(), $this->request(), VerificationRejectReason::from($this->reason), $this->note),
            __('ID of :code rejected; the member has been told what to do.', ['code' => $this->code]));
    }

    public function escalate(EscalateVerification $escalate): mixed
    {
        return $this->decide(fn () => $escalate->handle($this->admin(), $this->request(), $this->escalationNote), __('ID of :code escalated to a super admin.', ['code' => $this->code]));
    }

    /** Give the request back to the queue without deciding ("skip"). */
    public function release(ClaimVerificationRequest $claim, DocumentViewerAccess $access): mixed
    {
        $claim->release($this->admin(), $this->request());
        $access->forget($this->code);

        return $this->redirectRoute('admin.verification.index', navigate: true);
    }

    public function viewDocuments(OpenVerificationDocuments $open): void
    {
        try {
            $this->documentUrls = $open->handle($this->admin(), $this->request());
            $this->viewerUntil = now()->addMinutes(DocumentViewerAccess::minutes())->getTimestamp();
        } catch (VerificationRequestUnavailable|AuthorizationException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    public function closeViewer(DocumentViewerAccess $access): void
    {
        $access->forget($this->code);
        $this->documentUrls = [];
        $this->viewerUntil = 0;
    }

    public function render(DocumentViewerAccess $access, CurrentMembership $membership): View
    {
        $request = $this->request();
        $request->load(['documents', 'decidedBy:id,name', 'claimedBy:id,name', 'profile.user']);
        $profile = $request->profile ?? abort(404);
        $admin = $this->admin();

        if ($this->viewerUntil !== 0 && $this->viewerUntil < now()->getTimestamp()) {
            $this->documentUrls = [];
            $this->viewerUntil = 0;
        }

        return view('livewire.admin.verification.review', [
            'request' => $request,
            'profile' => $profile,
            'member' => $profile->user,
            'plan' => $membership->current($profile)?->plan?->name,
            'history' => VerificationRequest::query()->where('profile_id', $profile->id)->whereKeyNot($request->id)
                ->with('decidedBy:id,name')->latest('submitted_at')->limit(10)->get(),
            'flags' => collect($request->flags ?? [])->map(fn (array $flag): array => [
                'flag' => VerificationFlag::tryFrom($flag['code']),
                'detail' => $flag['detail'],
            ])->filter(fn (array $row): bool => $row['flag'] !== null)->values(),
            'reasons' => VerificationRejectReason::options(),
            'canDecide' => $request->status->isOpen() && $this->claimedBy === null && $admin->can('verification.approve')
                && ($request->status !== VerificationStatus::Escalated || $admin->isSuperAdmin()),
            'canViewDocuments' => $access->canView($admin, $request) && $request->documents->isNotEmpty(),
            'hasDocumentPermission' => $admin->can('verification.document.view'),
            'watermark' => __(':email · :time IST · access logged', [
                'email' => $admin->email,
                'time' => now()->timezone((string) config('oppam.display_timezone'))->format('d M Y H:i'),
            ]),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function decide(Closure $action, string $done): mixed
    {
        try {
            $action();
        } catch (VerificationRequestUnavailable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return null;
        }

        app(DocumentViewerAccess::class)->forget($this->code);
        session()->flash('status', $done);

        return $this->redirectRoute('admin.verification.index', navigate: true);
    }

    private function request(): VerificationRequest
    {
        // Scoped to the profile in the URL: a tampered id can't reach another member's request.
        return VerificationRequest::query()
            ->whereKey($this->requestId)
            ->whereHas('profile', fn ($q) => $q->withTrashed()->where('code', $this->code))
            ->first() ?? abort(404);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
