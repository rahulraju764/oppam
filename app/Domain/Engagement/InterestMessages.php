<?php

declare(strict_types=1);

namespace App\Domain\Engagement;

use App\Data\Masters\MasterItem;
use App\Domain\Masters\MasterLists;
use App\Domain\Moderation\ModerationFlags;
use App\Enums\PlanCode;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\Profile;
use App\Services\Entitlements\EntitlementService;
use App\Services\Masters\Masters;
use Illuminate\Validation\ValidationException;

/**
 * The optional note on an interest (PRD M06 `Shared\InterestButton`: "5 templated messages
 * (+ custom for paid)", §7.2 `interests.message` 300). A template is chosen by its code from the
 * A11 list — the interest keeps its own copy of the text; a custom note needs a paid plan, at
 * most MAX characters, and may not carry contact details or abusive words (the same check as
 * profile text, A04).
 */
final class InterestMessages
{
    public const MAX = 300;

    public function __construct(
        private readonly Masters $masters,
        private readonly EntitlementService $entitlements,
        private readonly ModerationFlags $flags,
    ) {}

    /** @return list<MasterItem> the active ready messages, in A11 order */
    public function templates(): array
    {
        return $this->masters->options(MasterLists::INTEREST_TEMPLATES);
    }

    public function allowsCustom(Profile $sender): bool
    {
        return $this->entitlements->plan($sender)->code !== PlanCode::Free;
    }

    /**
     * The text to store, or null for "no note".
     *
     * @throws ValidationException unknown template (key `template`); custom note too long or with contact details / abuse (key `message`)
     * @throws EngagementRefused a custom note on the Free plan
     */
    public function resolve(Profile $sender, ?string $templateCode, ?string $custom): ?string
    {
        $custom = trim((string) $custom);

        if ($custom !== '') {
            if (! $this->allowsCustom($sender)) {
                throw EngagementRefused::customMessageNeedsPlan();
            }

            if (mb_strlen($custom) > self::MAX) {
                throw ValidationException::withMessages(['message' => __('Keep your message within :max characters.', ['max' => self::MAX])]);
            }

            if ($this->flags->forTexts([__('your message') => $custom]) !== []) {
                throw ValidationException::withMessages(['message' => __('Please don’t share phone numbers, email, social handles or abusive words here. You can talk once you are connected.')]);
            }

            return $custom;
        }

        if ($templateCode === null || $templateCode === '') {
            return null;
        }

        foreach ($this->templates() as $template) {
            if ($template->code === $templateCode) {
                return mb_substr($template->label, 0, self::MAX);
            }
        }

        throw ValidationException::withMessages(['template' => __('Pick one of the ready messages.')]);
    }
}
