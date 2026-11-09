<?php

declare(strict_types=1);

use App\Actions\Admin\Comms\EndAnnouncement;
use App\Actions\Admin\Comms\SaveAnnouncement;
use App\Actions\Comms\DismissAnnouncement;
use App\Broadcasting\AnnouncementsChannel;
use App\Data\Comms\AnnouncementData;
use App\Domain\Comms\ActiveAnnouncements;
use App\Enums\AnnouncementAudience;
use App\Enums\UserStatus;
use App\Events\Comms\AnnouncementPublished;
use App\Exceptions\Admin\CommsRefused;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Jobs\Comms\PublishDueAnnouncements;
use App\Livewire\Shared\AnnouncementBanner;
use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P8.3a — A14 announcements: "site-wide or segment banner, scheduled start/end, dismissible;
| delivered live via broadcast to online users". The live signal carries a code only, on a private
| channel every active member may join; what a member sees is decided server-side.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
});

function annData(array $overrides = []): AnnouncementData
{
    return new AnnouncementData(...[
        'title' => 'Onam offer',
        'body' => '20 % off Gold this week.',
        'actionUrl' => '/plans',
        'actionText' => 'See plans',
        'startsAt' => null,
        'endsAt' => null,
        'audience' => AnnouncementAudience::All,
        'isDismissible' => true,
        ...$overrides,
    ]);
}

it('A14: a live announcement goes out at once on `announcements` with its code only — no id, no text', function (): void {
    Event::fake([AnnouncementPublished::class]);

    $row = app(SaveAnnouncement::class)->handle(adminWithRole(), annData());

    expect($row->code)->toMatch('/^ANN[A-Z0-9]{8}$/')->and($row->broadcast_at)->not->toBeNull();
    Event::assertDispatched(AnnouncementPublished::class, function (AnnouncementPublished $e) use ($row): bool {
        return $e->broadcastOn()->name === 'private-announcements' && $e->broadcastWith() === ['code' => $row->code]
            && $e->broadcastAs() === 'announcement.published';
    });
});

it('A14 schedule: a future one waits; the minute job sends it once when its start comes', function (): void {
    Event::fake([AnnouncementPublished::class]);
    $row = app(SaveAnnouncement::class)->handle(adminWithRole(), annData(['startsAt' => CarbonImmutable::now()->addHour()]));
    Event::assertNotDispatched(AnnouncementPublished::class);
    expect(app(ActiveAnnouncements::class)->forMember(bride()))->toBeEmpty();

    $this->travel(61)->minutes();
    (new PublishDueAnnouncements)->handle(app(ActiveAnnouncements::class));
    (new PublishDueAnnouncements)->handle(app(ActiveAnnouncements::class));   // a second run sends nothing

    Event::assertDispatchedTimes(AnnouncementPublished::class, 1);
    expect($row->refresh()->broadcast_at)->not->toBeNull()
        ->and(app(ActiveAnnouncements::class)->forMember(bride('+919800000097'))->pluck('code')->all())->toBe([$row->code]);
});

it('A14 audience: free-only, paid-only and everyone, as ad slots decide paid (profiles.is_premium)', function (): void {
    $free = bride();
    $paid = groom();
    $paid->profile->forceFill(['is_premium' => true])->save();

    $all = app(SaveAnnouncement::class)->handle(adminWithRole(), annData(['title' => 'All']));
    $freeOnly = app(SaveAnnouncement::class)->handle(adminWithRole(), annData(['title' => 'Free', 'audience' => AnnouncementAudience::FreeOnly]));
    $paidOnly = app(SaveAnnouncement::class)->handle(adminWithRole(), annData(['title' => 'Paid', 'audience' => AnnouncementAudience::Paid]));
    Cache::flush();

    $codes = fn ($member): array => app(ActiveAnnouncements::class)->forMember($member->refresh())->pluck('code')->sort()->values()->all();
    expect($codes($free))->toBe(collect([$all->code, $freeOnly->code])->sort()->values()->all())
        ->and($codes($paid))->toBe(collect([$all->code, $paidOnly->code])->sort()->values()->all());
});

it('A14 dismiss: a dismissed one is gone for that member only; a non-dismissible one can\'t be dismissed', function (): void {
    $member = bride();
    $other = groom();
    $open = app(SaveAnnouncement::class)->handle(adminWithRole(), annData());
    $sticky = app(SaveAnnouncement::class)->handle(adminWithRole(), annData(['title' => 'Maintenance tonight', 'isDismissible' => false]));

    app(DismissAnnouncement::class)->handle($member, $open->code);
    app(DismissAnnouncement::class)->handle($member, $open->code);   // idempotent

    expect(AnnouncementDismissal::query()->count())->toBe(1)
        ->and(app(ActiveAnnouncements::class)->forMember($member)->pluck('code')->all())->toBe([$sticky->code])
        ->and(app(ActiveAnnouncements::class)->forMember($other)->pluck('code')->all())->toContain($open->code);

    expect(fn () => app(DismissAnnouncement::class)->handle($member, $sticky->code))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(DismissAnnouncement::class)->handle($member, 'ANNNOTREAL1'))->toThrow(NotFoundHttpException::class);
});

it('A14 dismiss is scoped to the member\'s audience: a free member can\'t dismiss a paid-only one (404), and ending by its window signals once', function (): void {
    $free = bride();
    $paidOnly = app(SaveAnnouncement::class)->handle(adminWithRole(), annData(['audience' => AnnouncementAudience::Paid]));

    expect(fn () => app(DismissAnnouncement::class)->handle($free, $paidOnly->code))->toThrow(NotFoundHttpException::class);
    expect(AnnouncementDismissal::query()->count())->toBe(0);

    $timed = app(SaveAnnouncement::class)->handle(adminWithRole(), annData(['title' => 'Timed', 'endsAt' => CarbonImmutable::now()->addMinutes(5)]));
    Event::fake([AnnouncementPublished::class]);
    $this->travel(20)->minutes();   // the job ran late: still signalled, once
    (new PublishDueAnnouncements)->handle(app(ActiveAnnouncements::class));
    (new PublishDueAnnouncements)->handle(app(ActiveAnnouncements::class));

    Event::assertDispatchedTimes(AnnouncementPublished::class, 1);
    expect($timed->refresh()->end_signalled_at)->not->toBeNull();
});

it('A14 end now: it leaves every member page live, and ended is final', function (): void {
    $row = app(SaveAnnouncement::class)->handle(adminWithRole(), annData());
    Event::fake([AnnouncementPublished::class]);

    app(EndAnnouncement::class)->handle(adminWithRole(), $row);

    Event::assertDispatched(AnnouncementPublished::class, fn ($e): bool => $e->code === $row->code);
    expect(app(ActiveAnnouncements::class)->forMember(bride()))->toBeEmpty()
        ->and(fn () => app(EndAnnouncement::class)->handle(adminWithRole(), $row))->toThrow(CommsRefused::class)
        ->and(fn () => app(SaveAnnouncement::class)->handle(adminWithRole(), annData(), $row))->toThrow(CommsRefused::class);
});

it('A14 validation: unsafe links, a button half-filled, an end in the past or before the start — nothing saved', function (array $overrides): void {
    expect(fn () => app(SaveAnnouncement::class)->handle(adminWithRole(), annData($overrides)))->toThrow(ValidationException::class);
    expect(Announcement::query()->count())->toBe(0);
})->with([
    'javascript link' => [['actionUrl' => 'javascript:alert(1)']],
    'http link' => [['actionUrl' => 'http://evil.example']],
    'protocol-relative' => [['actionUrl' => '//evil.example/x']],
    'link without text' => [['actionText' => '']],
    'end in the past' => [['endsAt' => CarbonImmutable::now()->subHour()]],
    'end before start' => [['startsAt' => CarbonImmutable::now()->addDays(2), 'endsAt' => CarbonImmutable::now()->addDay()]],
    'empty title' => [['title' => '  ']],
    'body too long' => [['body' => str_repeat('x', 501)]],
]);

it('A14: writing needs notifications.broadcast (403); reading needs campaigns.view', function (): void {
    $editor = adminWithRole('content_editor');   // campaigns.view, no notifications.broadcast

    expect(fn () => app(SaveAnnouncement::class)->handle($editor, annData()))->toThrow(AuthorizationException::class);

    signInAdmin($this, $editor);
    $this->get(adminUrl('/comms/announcements'))->assertOk();
    signInAdmin($this, adminWithRole('support'));
    $this->get(adminUrl('/comms/announcements'))->assertForbidden();
});

it('§9.3 announcements channel: an active member yes; a suspended one, a broker login, an admin or a support session no', function (): void {
    $channel = app(AnnouncementsChannel::class);
    $member = bride();

    expect($channel->join($member))->toBeTrue()
        ->and($channel->join(adminWithRole()))->toBeFalse()
        ->and($channel->join(null))->toBeFalse();

    $broker = App\Models\User::factory()->create(['role' => App\Enums\UserRole::Broker]);
    expect($channel->join($broker))->toBeFalse();

    $member->forceFill(['status' => UserStatus::Suspended])->save();
    expect($channel->join($member->refresh()))->toBeFalse();

    $other = groom();
    $this->actingAs($other, 'web');
    chatImpersonate($other);
    expect(app(AnnouncementsChannel::class)->join($other))->toBeFalse();
});

it('banner: shows the member\'s announcements, escaped; dismiss hides it; it listens on the private channel', function (): void {
    $member = bride();
    app(SaveAnnouncement::class)->handle(adminWithRole(), annData(['title' => '<b>Onam</b> offer']));
    $this->actingAs($member, 'web');

    $banner = Livewire::test(AnnouncementBanner::class)
        ->assertSee('&lt;b&gt;Onam&lt;/b&gt; offer', false)->assertDontSeeHtml('<b>Onam</b>')
        ->assertSeeHtml('href="/plans"');
    expect($banner->instance()->getListeners())->toHaveKey('echo-private:announcements,.announcement.published');

    $code = Announcement::query()->sole()->code;
    $banner->call('dismiss', $code)->assertDontSee('Onam');
});

it('banner: in an admin support session it shows but can\'t dismiss and doesn\'t listen', function (): void {
    $member = bride();
    $row = app(SaveAnnouncement::class)->handle(adminWithRole(), annData());
    $this->actingAs($member, 'web');
    chatImpersonate($member);

    $banner = Livewire::test(AnnouncementBanner::class)->call('dismiss', $row->code)->assertSee('Onam offer');
    expect($banner->instance()->getListeners())->toBe([])
        ->and(AnnouncementDismissal::query()->count())->toBe(0)
        ->and(fn () => app(DismissAnnouncement::class)->handle($member, $row->code))->toThrow(ImpersonationRestricted::class);
});
