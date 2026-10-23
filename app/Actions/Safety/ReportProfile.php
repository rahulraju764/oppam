<?php

declare(strict_types=1);

namespace App\Actions\Safety;

use App\Domain\Profile\ProfileVisibility;
use App\Domain\Safety\AbuseCases;
use App\Enums\ReportReason;
use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use App\Enums\SettingKey;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\ReportRefused;
use App\Models\Block;
use App\Models\Profile;
use App\Models\Report;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Report a member from their profile page (PRD M09 "Report with reasons", A10, F07):
 *  - the member must be able to see the profile, or have blocked it themselves ("block, then
 *    report" from /settings/blocked) — else 404; an ACTIVE account of their own;
 *  - a reason from the M09 list; details up to 500 characters (required for "something else");
 *  - `safety.reports_max_per_hour` per member (429); one OPEN profile report per member reported (409).
 * The report joins the reported member's A10 case (AbuseCases: triage, escalation, P0 paging) in
 * the same transaction. Nothing is frozen or hidden by a report alone, and the reported member is
 * never told. Never during an admin support session.
 */
final class ReportProfile
{
    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly AbuseCases $cases,
        private readonly RateLimiter $limiter,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws NotFoundHttpException
     * @throws ValidationException
     * @throws ReportRefused
     */
    public function handle(User $member, Profile $target, string $reason, ?string $details): Report
    {
        $this->impersonation->assertAllowed();

        $me = $member->isActive() ? $member->profile : null;
        // "Block, then report": a member may still report someone THEY blocked (their own block row —
        // it reveals nothing). A profile that blocked the reporter stays a 404.
        $blockedByMe = $me !== null && Block::query()->where('blocker_profile_id', $me->id)->where('blocked_profile_id', $target->id)->exists();
        if ($me === null || $me->id === $target->id || (! $blockedByMe && ! $this->visibility->canView($target, $member))) {
            throw new NotFoundHttpException;
        }

        $details = $details === null || trim($details) === '' ? null : trim($details);
        $data = Validator::make(['reason' => $reason, 'details' => $details], [
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:500', Rule::requiredIf($reason === ReportReason::Other->value)],
        ], [], ['reason' => __('reason'), 'details' => __('details')])->validate();

        $key = 'profile-report:'.$me->id;
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::SafetyReportsMaxPerHour))) {
            throw ReportRefused::tooMany();
        }
        $this->limiter->hit($key, 3600);

        return DB::transaction(function () use ($me, $target, $data, $details): Report {
            // Both profile rows in id order (as ReportChatMessages): the reported member's lock
            // serialises every report about them, so the case and its escalation count stay exact.
            Profile::query()->whereKey([$me->id, $target->id])->orderBy('id')->lockForUpdate()->get(['id']);
            if (Report::query()->where('reporter_profile_id', $me->id)->where('reported_profile_id', $target->id)
                ->where('source', ReportSource::Profile->value)->where('status', ReportStatus::Open->value)->exists()) {
                throw ReportRefused::alreadyReported();
            }

            $report = new Report;
            $report->reporter_profile_id = $me->id;
            $report->reported_profile_id = $target->id;
            $report->source = ReportSource::Profile;
            $report->reason = ReportReason::from($data['reason']);
            $report->details = $details;
            $report->status = ReportStatus::Open;
            $report->save();

            $this->cases->attach($report);

            return $report;
        });
    }
}
