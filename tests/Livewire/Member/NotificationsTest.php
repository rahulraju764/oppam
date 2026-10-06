<?php

declare(strict_types=1);

use App\Livewire\Member\Notifications\Index;
use App\Livewire\Shared\NotificationBell;
use App\Models\User;
use App\Notifications\Member\DailyMatchesReady;
use App\Notifications\Profile\ProfileApproved;
use Database\Seeders\PlansSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P3.2 — the bell (live count, lazy dropdown, mark all read, cross-tab sync) and /notifications
| (filters, unread, load more, grouped by day, mark read / delete — own only).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
});

function ntUser(string $phone = '+919877700001'): User
{
    $member = memberWithPhone($phone);
    $member->forceFill(['email_verified_at' => now()])->save();

    return $member->refresh();
}

it('M08 acceptance: the bell runs only the count query until it is opened', function (): void {
    $me = ntUser();
    $me->notify(new DailyMatchesReady(2));
    $this->actingAs($me, 'web');

    DB::enableQueryLog();
    $bell = Livewire::test(NotificationBell::class, ['variant' => 'desktop'])->assertSet('unread', 1)->assertSet('items', null);
    $notificationQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, '`notifications`'));
    expect($notificationQueries)->toHaveCount(1)->and($notificationQueries->first())->toContain('count(');

    $bell->call('open')->assertCount('items', 1)->assertSee('Your 2 new matches are ready');
});

it('counts only the member\'s own unread notifications', function (): void {
    $me = ntUser();
    ntUser('+919877700002')->notify(new DailyMatchesReady(5));
    $me->notify(new DailyMatchesReady(1));
    $this->actingAs($me, 'web');

    Livewire::test(NotificationBell::class)->assertSet('unread', 1)->call('open')->assertDontSee('5 new matches');
});

it('mark all read zeroes the bell; another tab\'s ".notifications.read" sets the count it carries', function (): void {
    $me = ntUser();
    $me->notify(new DailyMatchesReady(1));
    $me->notify(new ProfileApproved($me->profile->code));
    $this->actingAs($me, 'web');

    Livewire::test(NotificationBell::class)->assertSet('unread', 2)->call('markAllRead')->assertSet('unread', 0)
        ->assertDispatched('notifications-all-read');

    Livewire::test(NotificationBell::class)->call('syncCount', ['unread_count' => 7])->assertSet('unread', 7);
});

it('the bell listens to its own live channel, the read signal and reconnects', function (): void {
    $me = ntUser();
    $this->actingAs($me, 'web');

    $listeners = Livewire::test(NotificationBell::class)->instance()->getListeners();

    expect($listeners)->toHaveKey('echo-private:App.Models.User.'.$me->id.',.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated')
        ->toHaveKey('echo-private:App.Models.User.'.$me->id.',.notifications.read')
        ->toHaveKey('realtime-reconnected');
});

it('the bell\'s count and items can\'t be tampered with', function (string $property): void {
    $this->actingAs(ntUser(), 'web');

    expect(fn () => Livewire::test(NotificationBell::class)->set($property, $property === 'unread' ? 99 : []))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['unread', 'items', 'variant']);

it('/notifications: needs a member; lists newest first, grouped by day, with the unread count', function (): void {
    $this->get(memberUrl('/notifications'))->assertRedirect(route('login'));

    $me = ntUser();
    $me->notify(new ProfileApproved($me->profile->code));
    $me->notify(new DailyMatchesReady(4));
    DatabaseNotification::query()->where('type', 'profile_approved')->update(['created_at' => now()->subDays(3)]);
    $this->actingAs($me, 'web');

    $this->get(memberUrl('/notifications'))->assertOk()->assertSee('Notifications');
    Livewire::test(Index::class)->assertSee('2 unread')->assertSeeInOrder(['Today', 'Your 4 new matches are ready', 'Earlier', 'Your profile is live']);
});

it('filters by type (in the URL) and unread only', function (): void {
    $me = ntUser();
    $me->notify(new ProfileApproved($me->profile->code));
    $me->notify(new DailyMatchesReady(4));
    DatabaseNotification::query()->where('type', 'profile_approved')->update(['read_at' => now()]);
    $this->actingAs($me, 'web');

    Livewire::withQueryParams(['type' => 'matches'])->test(Index::class)
        ->assertSee('new matches are ready')->assertDontSee('Your profile is live')
        ->set('group', 'system')->assertSee('Your profile is live')->assertDontSee('new matches')
        ->set('group', '')->set('unread', true)->assertSee('new matches')->assertDontSee('Your profile is live')
        ->set('group', 'nonsense')->assertSet('group', '');
});

it('loads 20 at a time', function (): void {
    $me = ntUser();
    foreach (range(1, 23) as $i) {
        $me->notify(new DailyMatchesReady($i));
    }
    $this->actingAs($me, 'web');

    Livewire::test(Index::class)->assertCount('items', 20)->call('loadMore')->assertCount('items', 23)->assertSet('cursor', null);
});

it('mark one read and delete one — only the member\'s own; other ids change nothing', function (): void {
    $me = ntUser();
    $other = ntUser('+919877700003');
    $me->notify(new DailyMatchesReady(1));
    $other->notify(new DailyMatchesReady(9));
    $mine = (string) DatabaseNotification::query()->where('notifiable_id', $me->id)->value('id');
    $theirs = (string) DatabaseNotification::query()->where('notifiable_id', $other->id)->value('id');
    $this->actingAs($me, 'web');

    Livewire::test(Index::class)
        ->call('markRead', $theirs)->call('delete', $theirs)
        ->call('markRead', $mine)->assertSee('0 unread')->assertDispatched('notifications-changed')
        ->call('delete', $mine)->assertSee('No notifications yet');

    expect(DatabaseNotification::query()->whereKey($theirs)->whereNull('read_at')->exists())->toBeTrue();
});

it('the list and its cursor can\'t be tampered with', function (string $property): void {
    $this->actingAs(ntUser(), 'web');

    expect(fn () => Livewire::test(Index::class)->set($property, $property === 'hasNew' ? true : []))->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['items', 'cursor', 'hasNew']);

it('far down the list, mark read / delete change that one row in place — the loaded rows stay', function (): void {
    $me = ntUser();
    foreach (range(1, 45) as $i) {
        $me->notify(new DailyMatchesReady($i));
    }
    $this->actingAs($me, 'web');

    $page = Livewire::test(Index::class)->call('loadMore')->assertCount('items', 40);
    $rows = $page->get('items');
    $thirtieth = $rows[29]['id'];

    $page->call('markRead', $thirtieth)->assertCount('items', 40);
    expect(collect($page->get('items'))->firstWhere('id', $thirtieth)['unread'])->toBeFalse()
        ->and(DatabaseNotification::query()->whereKey($thirtieth)->whereNotNull('read_at')->exists())->toBeTrue();

    $page->call('delete', $rows[31]['id'])->assertCount('items', 39)
        ->call('loadMore')->assertCount('items', 44)->assertSet('cursor', null);
});

it('a new live notification re-reads the first page, but never collapses a list the member has scrolled into', function (): void {
    $me = ntUser();
    foreach (range(1, 45) as $i) {
        $me->notify(new DailyMatchesReady($i));
    }
    $this->actingAs($me, 'web');

    $listeners = Livewire::test(Index::class)->instance()->getListeners();
    expect($listeners)->not->toHaveKey('notifications-changed')   // its own event must not make it reload
        ->toHaveKey('notifications-all-read');

    $firstPage = Livewire::test(Index::class);
    $this->travel(1)->minutes();   // a later arrival: rows of the same second have no order among themselves
    $me->notify(new ProfileApproved($me->profile->code));
    $firstPage->call('arrived')->assertSet('hasNew', false)->assertCount('items', 20)->assertSee('Your profile is live');

    $scrolled = Livewire::test(Index::class)->call('loadMore')->assertCount('items', 40);
    $scrolled->call('arrived')->assertSet('hasNew', true)->assertCount('items', 40)->assertSee('New notifications')
        ->call('reload')->assertSet('hasNew', false)->assertCount('items', 20);
});

it('"mark all as read" (here or in the bell) clears the marks in place; in the unread view the list empties', function (): void {
    $me = ntUser();
    $me->notify(new DailyMatchesReady(1));
    $me->notify(new DailyMatchesReady(2));
    $this->actingAs($me, 'web');

    $all = Livewire::test(Index::class)->call('markAllRead')->assertCount('items', 2)->assertSee('0 unread')->assertDispatched('notifications-changed');
    expect(collect($all->get('items'))->pluck('unread')->all())->toBe([false, false]);

    DatabaseNotification::query()->update(['read_at' => null]);
    Livewire::test(Index::class)->set('unread', true)->assertCount('items', 2)
        ->call('showAllRead')->assertCount('items', 0);   // the bell's event
});

it('P1.7b: an admin support session can neither read nor delete the member\'s notifications', function (): void {
    $me = ntUser();
    $me->notify(new DailyMatchesReady(1));
    $id = (string) DatabaseNotification::query()->where('notifiable_id', $me->id)->value('id');
    $this->actingAs($me, 'web');
    session([App\Services\Admin\Impersonation::SESSION_KEY => 'imp-1']);

    Livewire::test(Index::class)
        ->call('markRead', $id)->assertDispatched('toast')->assertNotDispatched('notifications-changed')
        ->call('markAllRead')->call('delete', $id)->assertCount('items', 1)->assertSee('1 unread');
    Livewire::test(NotificationBell::class)->call('markAllRead')->assertSet('unread', 1)->assertDispatched('toast')
        ->assertNotDispatched('notifications-all-read');

    expect(DatabaseNotification::query()->whereKey($id)->whereNull('read_at')->exists())->toBeTrue()
        ->and(fn () => app(App\Actions\Notifications\UpdateNotifications::class)->delete($me, $id))
        ->toThrow(App\Exceptions\Admin\ImpersonationRestricted::class);
});

it('the bell shows "all caught up" when there is nothing, and the action buttons carry a loading state', function (): void {
    $me = ntUser();
    $this->actingAs($me, 'web');

    Livewire::test(NotificationBell::class)->call('open')->assertSee('all caught up');

    $me->notify(new DailyMatchesReady(1));
    Livewire::test(Index::class)->assertSeeHtml('wire:loading.attr="disabled" wire:target="markRead(')
        ->assertSeeHtml('wire:loading.attr="disabled" wire:target="delete(');
});

it('admin-typed and member-typed text in a notification is escaped where it is shown', function (): void {
    $me = ntUser();
    $me->notify(new App\Notifications\Account\AdminMessage('<script>alert(1)</script> Hello', 'Body <img src=x onerror=alert(2)> text here.'));
    $this->actingAs($me, 'web');

    Livewire::test(Index::class)->assertDontSeeHtml('<script>alert(1)</script>')->assertDontSeeHtml('<img src=x')
        ->assertSee('<script>alert(1)</script> Hello');
    Livewire::test(NotificationBell::class)->call('open')->assertDontSeeHtml('<script>alert(1)</script>');
});
