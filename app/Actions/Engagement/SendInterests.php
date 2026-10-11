<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Domain\Engagement\Connections;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Send interest to all selected" on /favorites (PRD M06, "respecting quota"): SendInterest for
 * each profile code in turn, with the same ready message. A profile that can't receive one (not
 * visible any more, contact filter, cooldown, already connected, or one that has itself sent an
 * interest waiting for an answer) is skipped; the run stops at the first one the month's quota no
 * longer covers. At most MAX codes per call.
 */
final class SendInterests
{
    public const MAX = 20;

    public function __construct(
        private readonly SendInterest $send,
        private readonly Connections $connections,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @param  list<mixed>  $profileCodes
     * @return array{sent: int, skipped: int, quotaReached: bool}
     *
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, array $profileCodes, ?string $templateCode = null): array
    {
        $this->impersonation->assertAllowed();

        $codes = array_slice(array_values(array_unique(array_filter(
            $profileCodes,
            fn (mixed $code): bool => is_string($code) && preg_match('/^OPM\d{1,10}$/', $code) === 1,
        ))), 0, self::MAX);

        $result = ['sent' => 0, 'skipped' => 0, 'quotaReached' => false];

        $own = $member->profile;
        $targets = Profile::query()->whereIn('code', $codes)->with(['privacySetting', 'partnerPreference'])->get();
        $states = $own !== null ? $this->connections->statesFor($own, $targets->pluck('id')->all()) : [];

        foreach ($targets as $target) {
            // A ticked profile that has itself sent an interest: answering it is the member's own,
            // deliberate step on /interests — a bulk action never accepts for them.
            if (($states[$target->id] ?? null) === Connections::RECEIVED) {
                $result['skipped']++;

                continue;
            }

            try {
                $this->send->handle($member, $target, $templateCode)->changed ? $result['sent']++ : $result['skipped']++;
            } catch (QuotaExceeded) {
                $result['quotaReached'] = true;
                break;
            } catch (EngagementRefused $refused) {
                $result['skipped']++;

                if ($refused->reason === EngagementRefused::TOO_FAST) {
                    break;   // the per-minute budget is used: the rest would only be refused too
                }
            } catch (NotFoundHttpException|ValidationException) {
                $result['skipped']++;
            }
        }

        return $result;
    }
}
