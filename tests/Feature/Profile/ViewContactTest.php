<?php

declare(strict_types=1);

use App\Actions\Profile\ViewContact;
use App\Domain\Profile\ContactAccessPolicy;
use App\Enums\ContactAccess;
use App\Enums\Entitlement;
use App\Enums\PhoneVisibility;
use App\Enums\PlanCode;
use App\Exceptions\Profile\ContactNotAvailable;
use App\Models\Block;
use App\Models\ContactDetail;
use App\Models\ContactView;
use App\Models\PartnerPreference;
use App\Models\Plan;
use App\Models\PrivacySetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use Database\Seeders\PlansSeeder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P1.5 — R-M03-2: contact details open only with a contact-view entitlement, a phone_visibility
| that allows it and a contact filter that admits the viewer; a reveal is charged once per pair.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
});

function onPlan(User $user, PlanCode $plan): User
{
    Subscription::factory()->create([
        'profile_id' => $user->profile->id,
        'plan_id' => Plan::query()->where('code', $plan->value)->sole()->id,
    ]);
    app(EntitlementService::class)->forget($user->profile);

    return $user->refresh();
}

function brideWithContact(PhoneVisibility $visibility = PhoneVisibility::PremiumOnly, bool $filter = false): User
{
    $bride = bride();
    $contact = new ContactDetail;
    $contact->forceFill(['profile_id' => $bride->profile->id, 'contact_email' => 'family@example.com', 'contact_person' => 'Gopalan', 'contact_relation' => 'Father'])->save();
    $privacy = PrivacySetting::query()->whereKey($bride->profile->id)->first() ?? new PrivacySetting;
    $privacy->forceFill(['profile_id' => $bride->profile->id, 'phone_visibility' => $visibility, 'contact_filter_enabled' => $filter])->save();

    return $bride->refresh();
}

function reveal(User $viewer, User $target): App\Data\Profile\ContactCardData
{
    return app(ViewContact::class)->handle($viewer, $target->profile()->firstOrFail());
}

function contactViewsUsed(User $user): int
{
    return app(EntitlementService::class)->used($user->profile()->firstOrFail(), Entitlement::ContactViewsPerMonth);
}

it('R-M03-2: a Gold member reveals contact details; the pair is charged exactly once', function (): void {
    $bride = brideWithContact();
    $groom = onPlan(groom(), PlanCode::Gold);

    $card = reveal($groom, $bride);
    reveal($groom, $bride);
    reveal($groom, $bride);

    expect($card->phone)->toBe($bride->phone)
        ->and($card->email)->toBe('family@example.com')
        ->and($card->contactPerson)->toBe('Gopalan')
        ->and(contactViewsUsed($groom))->toBe(1)
        ->and(ContactView::query()->count())->toBe(1);
});

it('R-M03-2: refuses with the reason, and charges nothing', function (Closure $arrange, ContactAccess $reason): void {
    [$viewer, $target] = $arrange();

    try {
        reveal($viewer, $target);
        $this->fail('Expected ContactNotAvailable');
    } catch (ContactNotAvailable $e) {
        expect($e->access)->toBe($reason)->and($e->getMessage())->toBe($reason->message());
    }

    expect(ContactView::query()->count())->toBe(0)
        ->and(contactViewsUsed($viewer))->toBe(0);
})->with([
    'free plan' => [fn () => [groom(), brideWithContact()], ContactAccess::NeedsPlan],
    'phone hidden' => [fn () => [onPlan(groom(), PlanCode::Gold), brideWithContact(PhoneVisibility::Hidden)], ContactAccess::HiddenByMember],
    'accepted only (no interests yet)' => [fn () => [onPlan(groom(), PlanCode::Gold), brideWithContact(PhoneVisibility::AcceptedOnly)], ContactAccess::AcceptedOnly],
    'contact filter not met' => [function () {
        $bride = brideWithContact(filter: true);
        $preference = new PartnerPreference;
        $preference->forceFill(['profile_id' => $bride->profile->id, 'age_min' => 60, 'age_max' => 70])->save();

        return [onPlan(groom(), PlanCode::Gold), $bride->refresh()];
    }, ContactAccess::FilteredOut],
]);

it('R-M03-2: refuses when this month\'s contact views are used up', function (): void {
    $groom = onPlan(groom(), PlanCode::Gold);
    $limit = (int) app(EntitlementService::class)->limit($groom->profile, Entitlement::ContactViewsPerMonth);
    app(EntitlementService::class)->consume($groom->profile, Entitlement::ContactViewsPerMonth, $limit);

    expect(fn () => reveal($groom, brideWithContact()))->toThrow(ContactNotAvailable::class, ContactAccess::QuotaUsed->message());
});

it('a revealed pair stays open after the quota runs out, without a new charge', function (): void {
    $bride = brideWithContact();
    $groom = onPlan(groom(), PlanCode::Gold);
    reveal($groom, $bride);
    $limit = (int) app(EntitlementService::class)->limit($groom->profile, Entitlement::ContactViewsPerMonth);
    app(EntitlementService::class)->consume($groom->profile, Entitlement::ContactViewsPerMonth, $limit - 1);

    expect(app(ContactAccessPolicy::class)->decide($bride->profile, $groom->profile))->toBe(ContactAccess::Revealed)
        ->and(reveal($groom, $bride)->phone)->toBe($bride->phone)
        ->and(contactViewsUsed($groom))->toBe($limit);
});

it('a contact filter the viewer meets lets the reveal through', function (): void {
    $bride = brideWithContact(filter: true);
    $preference = new PartnerPreference;
    $preference->forceFill(['profile_id' => $bride->profile->id, 'age_min' => 18, 'age_max' => 70])->save();

    expect(reveal(onPlan(groom(), PlanCode::Gold), $bride->refresh())->phone)->toBe($bride->phone);
});

it('security matrix: blocked pair, same gender and the owner get "not found"', function (string $case): void {
    $bride = brideWithContact();
    $viewer = match ($case) {
        'blocked' => tap(onPlan(groom(), PlanCode::Gold), function (User $g) use ($bride): void {
            $block = new Block;
            $block->forceFill(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $g->profile->id])->save();
        }),
        'same gender' => onPlan(bride('+919800000093'), PlanCode::Gold),
        'owner' => $bride,
    };

    expect(fn () => reveal($viewer, $bride))->toThrow(NotFoundHttpException::class)
        ->and(ContactView::query()->count())->toBe(0);
})->with(['blocked', 'same gender', 'owner']);

it('P1.5 review Major: an earlier reveal never outlives the member hiding her phone or turning on a filter', function (string $change, ContactAccess $reason): void {
    $bride = brideWithContact();
    $groom = onPlan(groom(), PlanCode::Gold);
    reveal($groom, $bride);

    $privacy = PrivacySetting::query()->whereKey($bride->profile->id)->firstOrFail();
    if ($change === 'phone hidden') {
        $privacy->forceFill(['phone_visibility' => PhoneVisibility::Hidden])->save();
    } else {
        $preference = new PartnerPreference;
        $preference->forceFill(['profile_id' => $bride->profile->id, 'age_min' => 60, 'age_max' => 70])->save();
        $privacy->forceFill(['contact_filter_enabled' => true])->save();
    }

    expect(fn () => reveal($groom, $bride->refresh()))->toThrow(ContactNotAvailable::class, $reason->message());
})->with([
    'phone hidden' => ['phone hidden', ContactAccess::HiddenByMember],
    'filter turned on' => ['filter', ContactAccess::FilteredOut],
]);

it('a viewer whose own profile is SUSPENDED can\'t reveal contacts (owner decision 2026-10-01)', function (): void {
    $groom = onPlan(groom(), PlanCode::Gold);
    $groom->profile->forceFill(['status' => App\Enums\ProfileStatus::Suspended])->save();

    expect(fn () => reveal($groom->refresh(), brideWithContact()))->toThrow(NotFoundHttpException::class);
});
