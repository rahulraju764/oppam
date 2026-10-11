<?php

declare(strict_types=1);

use App\Actions\Engagement\RequestAccess;
use App\Actions\Engagement\RespondToAccessRequest;
use App\Domain\Media\PhotoAccess;
use App\Enums\AccessRequestStatus;
use App\Enums\AccessRequestType;
use App\Enums\PhotoVisibility;
use App\Enums\ProfileStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\AccessRequest;
use App\Models\Block;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Engagement\AccessRequestNotification;
use App\Queries\Engagement\InterestLists;
use App\Services\Admin\Impersonation;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P3.4 — photo / horoscope requests (PRD §10 M06 "Photo / Horoscope request": target sees the
| request, approves → the requester gets access). Only where the owner shares on request; one
| request per type and pair; only the owner answers; a decline is never announced.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

/** A bride who shows her photos only on request. */
function arOwner(PhotoVisibility $visibility = PhotoVisibility::OnRequest): User
{
    $bride = bride();
    $privacy = PrivacySetting::query()->whereKey($bride->profile->id)->first() ?? new PrivacySetting;
    $privacy->forceFill(['profile_id' => $bride->profile->id, 'photo_visibility' => $visibility])->save();

    return $bride->refresh();
}

function arAsk(User $requester, Profile $owner, AccessRequestType $type = AccessRequestType::Photo): bool
{
    return app(RequestAccess::class)->handle($requester, $owner, $type);
}

it('M06: asking to see photos shared on request leaves one pending request and tells the owner', function (): void {
    $groom = groom();
    $bride = arOwner();

    expect(arAsk($groom, $bride->profile))->toBeTrue()
        ->and(arAsk($groom, $bride->profile))->toBeTrue();   // asking again changes nothing

    $request = AccessRequest::query()->sole();
    expect($request->status)->toBe(AccessRequestStatus::Pending)->and($request->type)->toBe(AccessRequestType::Photo)
        ->and($request->requester_profile_id)->toBe($groom->profile->id)->and($request->owner_profile_id)->toBe($bride->profile->id)
        ->and(app(PhotoAccess::class)->canSeeClearly($bride->profile, $groom))->toBeFalse();

    Notification::assertSentToTimes($bride, AccessRequestNotification::class, 1);
    $content = (new AccessRequestNotification(AccessRequestType::Photo, $groom->profile->id))->content($bride);
    expect($content->title)->toContain('asked to see your photos')->and($content->url)->toBe(route('member.interests', ['box' => 'requests']));
});

it('M06: approving gives the requester access and tells them; declining gives nothing and tells nobody', function (bool $approve): void {
    $groom = groom();
    $bride = arOwner();
    arAsk($groom, $bride->profile);
    Notification::fake();

    app(RespondToAccessRequest::class)->handle($bride, $groom->profile, AccessRequestType::Photo, $approve);

    expect(AccessRequest::query()->sole()->status)->toBe($approve ? AccessRequestStatus::Approved : AccessRequestStatus::Declined)
        ->and(app(PhotoAccess::class)->canSeeClearly($bride->profile, $groom))->toBe($approve)
        ->and(app(PhotoAccess::class)->canSeeClearly($bride->profile, groom('+919800000067')))->toBeFalse();

    $approve
        ? Notification::assertSentTo($groom, AccessRequestNotification::class, fn (AccessRequestNotification $n): bool => $n->approved
            && str_contains($n->content($groom)->title, 'approved your request'))
        : Notification::assertNothingSent();

    // Answered once: it can't be answered again.
    expect(fn () => app(RespondToAccessRequest::class)->handle($bride, $groom->profile, AccessRequestType::Photo, true))->toThrow(EngagementRefused::class);
})->with(['approve' => [true], 'decline' => [false]]);

it('a declined request can be asked again only after the cooldown; an approved one needs no asking', function (): void {
    $groom = groom();
    $bride = arOwner();
    arAsk($groom, $bride->profile);
    app(RespondToAccessRequest::class)->handle($bride, $groom->profile, AccessRequestType::Photo, false);

    expect(fn () => arAsk($groom, $bride->profile))->toThrow(EngagementRefused::class);

    $this->travel(91)->days();
    expect(arAsk($groom, $bride->profile))->toBeTrue()->and(AccessRequest::query()->sole()->status)->toBe(AccessRequestStatus::Pending);

    app(RespondToAccessRequest::class)->handle($bride, $groom->profile, AccessRequestType::Photo, true);
    expect(fn () => arAsk($groom, $bride->profile))->toThrow(EngagementRefused::class);   // nothing left to request
});

it('there is nothing to request where the content isn\'t shared on request', function (PhotoVisibility $visibility): void {
    $groom = groom();
    $bride = arOwner($visibility);

    expect(fn () => arAsk($groom, $bride->profile))->toThrow(EngagementRefused::class)
        ->and(fn () => arAsk($groom, $bride->profile, AccessRequestType::Horoscope))->toThrow(EngagementRefused::class)   // no horoscope file, not on request
        ->and(AccessRequest::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['all members' => [PhotoVisibility::AllMembers], 'accepted only' => [PhotoVisibility::AcceptedOnly], 'premium only' => [PhotoVisibility::PremiumOnly]]);

it('refuses with a 404 and writes nothing', function (string $case): void {
    $groom = groom();
    $bride = arOwner();

    $owner = match ($case) {
        'own profile' => $groom->profile,
        'same gender' => groom('+919800000066')->profile,
        'owner not ACTIVE' => tap($bride->profile, fn (Profile $p) => $p->forceFill(['status' => ProfileStatus::Suspended])->save()),
        'blocked pair' => tap($bride->profile, fn (Profile $p) => Block::factory()->create(['blocker_profile_id' => $p->id, 'blocked_profile_id' => $groom->profile->id])),
    };

    expect(fn () => arAsk($groom, $owner))->toThrow(NotFoundHttpException::class)->and(AccessRequest::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['own profile', 'same gender', 'owner not ACTIVE', 'blocked pair']);

it('IDOR: only the member who was asked can answer a request', function (): void {
    $groom = groom();
    $bride = arOwner();
    $outsider = bride('+919800000065');
    arAsk($groom, $bride->profile);

    expect(fn () => app(RespondToAccessRequest::class)->handle($outsider, $groom->profile, AccessRequestType::Photo, true))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(RespondToAccessRequest::class)->handle($groom, $bride->profile, AccessRequestType::Photo, true))->toThrow(NotFoundHttpException::class)   // the requester approving himself
        ->and(fn () => app(RespondToAccessRequest::class)->handle($bride, $groom->profile, AccessRequestType::Horoscope, true))->toThrow(NotFoundHttpException::class)   // another type
        ->and(AccessRequest::query()->sole()->status)->toBe(AccessRequestStatus::Pending)
        ->and(app(PhotoAccess::class)->canSeeClearly($bride->profile, $groom))->toBeFalse();
});

it('P1.7b: an admin support session can neither ask nor answer for the member', function (): void {
    $groom = groom();
    $bride = arOwner();
    arAsk($groom, $bride->profile);
    session([Impersonation::SESSION_KEY => 'imp-1']);

    expect(fn () => arAsk($groom, $bride->profile))->toThrow(ImpersonationRestricted::class)
        ->and(fn () => app(RespondToAccessRequest::class)->handle($bride, $groom->profile, AccessRequestType::Photo, true))->toThrow(ImpersonationRestricted::class)
        ->and(AccessRequest::query()->sole()->status)->toBe(AccessRequestStatus::Pending);
});

it('a member whose own profile is not approved yet cannot ask', function (): void {
    $pending = memberWithPhone('+919800000064', ProfileStatus::PendingReview);
    $pending->profile->forceFill(['gender' => App\Enums\Gender::Male])->save();

    expect(fn () => arAsk($pending->refresh(), arOwner()->profile))->toThrow(EngagementRefused::class)->and(AccessRequest::query()->count())->toBe(0);
});

it('the owner\'s list of waiting requests: her own only, never from a blocked member', function (): void {
    $bride = arOwner();
    $asker = groom();
    $blocked = groom('+919800000063');
    AccessRequest::factory()->between($asker->profile, $bride->profile)->create();
    AccessRequest::factory()->between($blocked->profile, $bride->profile)->create();
    AccessRequest::factory()->between(groom('+919800000062')->profile, $bride->profile)->approved()->create();
    AccessRequest::factory()->between($asker->profile, bride('+919800000061')->profile)->create();
    Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $blocked->profile->id]);

    $rows = app(InterestLists::class)->requests($bride->profile)->get();

    expect($rows->pluck('code')->all())->toBe([$asker->profile->code])
        ->and($rows->first()->getAttribute('request_type'))->toBe('PHOTO');
});
