<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\DecidesModerationItems;
use App\Domain\Profile\PendingTextEdits;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\RejectReason;
use App\Events\Admin\ModerationQueueChanged;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AdminUser;
use App\Models\FamilyDetail;
use App\Models\ModerationItem;
use App\Models\PartnerPreference;
use App\Models\Profile;
use App\Notifications\Profile\ProfileContentRejected;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Decide the held text edits of a live profile (A04 edited-fields queue, R-M02-4). Approve writes
 * every held "table.column" value to the profile (only the columns PendingTextEdits allows);
 * reject drops them and tells the member why. The profile stays live either way. Audited with
 * the before / after text.
 */
final class DecideProfileEdit
{
    use DecidesModerationItems;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * $seen is ModerationItem::fieldsFingerprint() of the text the moderator was shown. The
     * member may keep editing (their changes merge into this same item), so the decision only
     * goes through when the held text is still exactly what was shown.
     *
     * @throws AuthorizationException
     * @throws ModerationItemUnavailable
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, ModerationItem $item, string $seen, bool $approve, ?RejectReason $reason = null, ?string $note = null): void
    {
        $this->authorizeDecision($admin, $item);

        if ($item->type !== ModerationItemType::ProfileEdit) {
            throw new InvalidArgumentException('Not an edited-fields item.');
        }

        $reason ??= RejectReason::Inappropriate;
        $note = $this->validatedNote($note, $approve ? null : $reason);
        $profile = Profile::query()->whereKey($item->profile_id)->with(['user', 'familyDetail', 'partnerPreference'])->firstOrFail();

        $fields = DB::transaction(function () use ($admin, $item, $seen, $approve, $reason, $note, $profile): array {
            $this->holdClaim($admin, $item);

            // Lock the row the member's edits merge into (PendingTextEdits::merge locks it too)
            // and decide on exactly the text that is locked — never on an earlier read.
            $locked = ModerationItem::query()->whereKey($item->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->status->isPending()) {
                throw ModerationItemUnavailable::alreadyDecided();
            }

            if (! hash_equals($locked->fieldsFingerprint(), $seen)) {
                throw ModerationItemUnavailable::changedSinceViewed();
            }

            $fields = is_array($locked->fields) ? $locked->fields : [];
            $before = [];

            if ($approve) {
                foreach ($fields as $key => $value) {
                    [$table, $column] = array_pad(explode('.', (string) $key, 2), 2, '');

                    // Only the moderated text columns can ever be written from a queue item.
                    if (! in_array($column, PendingTextEdits::FIELDS[$table] ?? [], true) || ! is_string($value)) {
                        continue;
                    }

                    $model = match ($table) {
                        'profiles' => $profile,
                        'family_details' => $profile->familyDetail ?? (new FamilyDetail)->forceFill(['profile_id' => $profile->id]),
                        'partner_preferences' => $profile->partnerPreference ?? (new PartnerPreference)->forceFill(['profile_id' => $profile->id]),
                        default => null,
                    };

                    if ($model !== null) {
                        $before[$key] = $model->getAttribute($column);
                        $model->forceFill([$column => $value])->save();
                    }
                }
            }

            $this->close($item, $admin, $approve ? ModerationStatus::Approved : ModerationStatus::Rejected, $approve ? null : $reason->value, $note);

            $this->audit->record($approve ? 'moderation.edit_approved' : 'moderation.edit_rejected', $profile,
                $before, $fields, reason: $note, actor: $admin, subjectLabel: $profile->code);

            return $fields;
        });

        ModerationQueueChanged::dispatch(ModerationItemType::ProfileEdit);

        if (! $approve) {
            $profile->user?->notify(new ProfileContentRejected(__('profile text update'), $reason, $note));
        }
    }
}
