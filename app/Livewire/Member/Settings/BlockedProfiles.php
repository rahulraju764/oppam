<?php

declare(strict_types=1);

namespace App\Livewire\Member\Settings;

use App\Actions\Safety\ReportProfile;
use App\Actions\Safety\UnblockProfile;
use App\Actions\Safety\UnignoreProfile;
use App\Data\Content\SeoData;
use App\Enums\ReportReason;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\ReportRefused;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\Block;
use App\Models\Ignore;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /settings/blocked (PRD M09): the members this member blocked and ignored, newest first, with
 * Unblock / Show again, and Report for a blocked member ("block, then report", decisions 2026-10-23). Only the member's OWN rows — never who blocked them. Blocked profiles show
 * code and first name only (no photo: the pair no longer sees each other). Every change goes
 * through UnblockProfile / UnignoreProfile, which re-check the row is the member's own.
 */
final class BlockedProfiles extends Component
{
    /** The report dialog ("block, then report"): which blocked member, reason, details. */
    public string $reportCode = '';

    public string $reportReason = '';

    public string $reportDetails = '';

    public function openReport(string $code): void
    {
        $this->reset('reportReason', 'reportDetails');
        $this->resetErrorBag();
        $this->reportCode = preg_match('/^OPM[0-9]+$/', $code) === 1 ? $code : '';
        $this->dispatch('open-modal', name: 'report-blocked');
    }

    /** ReportProfile re-checks that the member blocked this profile themselves (else 404). */
    public function report(ReportProfile $report): void
    {
        $profile = $this->reportCode !== '' ? Profile::query()->where('code', $this->reportCode)->first() : null;

        try {
            $report->handle($this->member(), $profile ?? throw new NotFoundHttpException, $this->reportReason, $this->reportDetails);
        } catch (NotFoundHttpException) {
            $this->dispatch('close-modal', name: 'report-blocked');
            $this->dispatch('toast', type: 'danger', title: __('This member is not on your list any more.'));

            return;
        } catch (ReportRefused|ImpersonationRestricted $refused) {
            $this->dispatch('close-modal', name: 'report-blocked');
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->reset('reportCode', 'reportReason', 'reportDetails');
        $this->dispatch('close-modal', name: 'report-blocked');
        $this->dispatch('toast', type: 'success', title: __('Thank you for telling us. Our safety team will review it — they won’t be told who reported.'));
    }

    public function unblock(string $code, UnblockProfile $unblock): void
    {
        $this->attempt(fn (Profile $p) => $unblock->handle($this->member(), $p), $code, __('Unblocked. You can see each other again.'));
    }

    public function unignore(string $code, UnignoreProfile $unignore): void
    {
        $this->attempt(fn (Profile $p) => $unignore->handle($this->member(), $p), $code, __('This member shows in your search and lists again.'));
    }

    public function render(): View
    {
        $me = $this->member()->profile;

        return view('livewire.member.settings.blocked-profiles', [
            'blocked' => $me === null ? collect() : Block::query()->where('blocker_profile_id', $me->id)
                ->with('blocked:id,code,first_name')->latest()->limit(200)->get(),
            'ignored' => $me === null ? collect() : Ignore::query()->where('ignorer_profile_id', $me->id)
                ->join('profiles', 'profiles.id', '=', 'ignores.ignored_profile_id')
                ->orderByDesc('ignores.created_at')->limit(200)
                ->get(['ignores.id', 'ignores.created_at', 'profiles.code', 'profiles.first_name']),
            'timezone' => (string) config('oppam.display_timezone'),
            'reasons' => ReportReason::options(),
        ])->layout('layouts::member', ['seo' => SeoData::private(__('Blocked & ignored').' | Oppam Matrimony')]);
    }

    private function attempt(callable $action, string $code, string $done): void
    {
        $profile = preg_match('/^OPM[0-9]+$/', $code) === 1 ? Profile::withTrashed()->where('code', $code)->first() : null;

        try {
            $action($profile ?? throw new NotFoundHttpException);
        } catch (NotFoundHttpException) {
            $this->dispatch('toast', type: 'danger', title: __('This member is not on your list any more.'));

            return;
        } catch (SafetyRefused|ImpersonationRestricted $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', title: $done);
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
