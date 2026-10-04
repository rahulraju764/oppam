<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Data\Profile\ContactCardData;
use App\Domain\Profile\ContactAccessPolicy;
use App\Domain\Profile\ProfileVisibility;
use App\Enums\ContactAccess;
use App\Enums\Entitlement;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Profile\ContactNotAvailable;
use App\Models\ContactView;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reveal a profile's contact details (R-M03-2). The viewer must be allowed to see the profile at
 * all (else 404) and ContactAccessPolicy must open it. The first reveal of a pair writes one
 * contact_views row and uses one monthly contact view, together in a transaction; every later
 * reveal of the same pair is free. A race between two clicks still charges once (unique pair).
 */
final class ViewContact
{
    public function __construct(
        private readonly ProfileVisibility $visibility,
        private readonly ContactAccessPolicy $policy,
        private readonly EntitlementService $entitlements,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws NotFoundHttpException the viewer may not see this profile
     * @throws ContactNotAvailable with the reason (plan, quota, privacy, filter)
     * @throws ImpersonationRestricted an admin impersonating the viewer
     */
    public function handle(User $viewer, Profile $target): ContactCardData
    {
        // Spends the member's contact-view quota: never on their behalf (owner decision 2026-10-02).
        $this->impersonation->assertAllowed();

        $own = $viewer->profile;

        if ($own === null || $this->visibility->isOwner($target, $viewer) || ! $this->visibility->canView($target, $viewer)) {
            throw new NotFoundHttpException;
        }

        $access = $this->policy->decide($target, $own);

        if (! $access->opens()) {
            throw new ContactNotAvailable($access);
        }

        if ($access === ContactAccess::CanReveal) {
            try {
                DB::transaction(function () use ($own, $target): void {
                    // insertOrIgnore on the unique pair: a concurrent first reveal leaves one row and one charge.
                    $inserted = ContactView::query()->insertOrIgnore([
                        'id' => (string) Str::ulid(),
                        'viewer_profile_id' => $own->id,
                        'viewed_profile_id' => $target->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($inserted === 1) {
                        $this->entitlements->consume($own, Entitlement::ContactViewsPerMonth);
                    }
                });
            } catch (QuotaExceeded) {
                // The last view went in another tab meanwhile: nothing was written (rolled back).
                throw new ContactNotAvailable(ContactAccess::QuotaUsed);
            }
        }

        return $this->card($target);
    }

    private function card(Profile $target): ContactCardData
    {
        $contact = $target->contactDetail;

        return new ContactCardData(
            phone: (string) $target->user?->phone,
            alternatePhone: $contact?->alternate_phone,
            email: $contact?->contact_email,
            contactPerson: $contact?->contact_person,
            relation: $contact?->contact_relation,
            convenientTime: $contact?->convenient_time,
        );
    }
}
