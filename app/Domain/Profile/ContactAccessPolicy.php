<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Domain\Matching\PreferenceMatcher;
use App\Enums\ContactAccess;
use App\Enums\Entitlement;
use App\Enums\PhoneVisibility;
use App\Models\ContactView;
use App\Models\Profile;
use App\Services\Entitlements\EntitlementService;

/**
 * R-M03-2: contact details open only when the viewer's plan has contact views left, the target's
 * phone_visibility allows it (PREMIUM_ONLY: any paid viewer with views; ACCEPTED_ONLY: an
 * accepted interest — P3.4; HIDDEN: never) and the target's contact filter (if on) is met by
 * the viewer. The privacy checks run on EVERY request — a member who later hides her phone or
 * turns on a filter is protected from earlier viewers too. A pair revealed before is only spared
 * the plan / quota check (no second charge).
 */
final class ContactAccessPolicy
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly PreferenceMatcher $matcher,
    ) {}

    public function decide(Profile $target, Profile $viewer): ContactAccess
    {
        $privacy = $target->privacySetting;

        $visibilityBlock = match ($privacy->phone_visibility ?? PhoneVisibility::PremiumOnly) {
            PhoneVisibility::Hidden => ContactAccess::HiddenByMember,
            PhoneVisibility::AcceptedOnly => ContactAccess::AcceptedOnly,   // until interests exist (P3.4)
            PhoneVisibility::PremiumOnly => null,
        };

        if ($visibilityBlock !== null) {
            return $visibilityBlock;
        }

        if (($privacy->contact_filter_enabled ?? false) && ! $this->matcher->meetsAll($viewer, $target->partnerPreference)) {
            return ContactAccess::FilteredOut;
        }

        if ($this->alreadyRevealed($target, $viewer)) {
            return ContactAccess::Revealed;
        }

        if ($this->entitlements->limit($viewer, Entitlement::ContactViewsPerMonth) === 0) {
            return ContactAccess::NeedsPlan;
        }

        return $this->entitlements->can($viewer, Entitlement::ContactViewsPerMonth)
            ? ContactAccess::CanReveal
            : ContactAccess::QuotaUsed;
    }

    public function alreadyRevealed(Profile $target, Profile $viewer): bool
    {
        return ContactView::query()
            ->where('viewer_profile_id', $viewer->id)
            ->where('viewed_profile_id', $target->id)
            ->exists();
    }
}
