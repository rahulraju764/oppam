<?php

declare(strict_types=1);

use App\Actions\Safety\BlockProfile;
use App\Actions\Safety\IgnoreProfile;
use App\Livewire\Member\Chat\Thread;
use App\Livewire\Member\Profile\SafetyMenu;
use App\Livewire\Member\Profile\Show;
use App\Livewire\Member\Settings\BlockedProfiles;
use App\Livewire\Member\Support\Help;
use App\Livewire\Member\Support\TicketThread;
use App\Livewire\Public\ContactForm;
use App\Models\Block;
use App\Models\Ignore;
use App\Models\Report;
use App\Models\SupportTicket;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P6.2 — member screens: the profile "More" menu (block / ignore / report), /settings/blocked,
| the live re-check on the profile page and the chat thread, /safety, /contact, /help.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

it('profile page: the More menu is on another member’s profile (not my own preview), with loading states', function (): void {
    $groom = groom();
    $bride = bride();

    $this->actingAs($groom)->get(memberUrl('/profile/'.$bride->profile->code))->assertOk()->assertSee('Report '.$bride->profile->first_name, false);

    Livewire::actingAs($groom, 'web')->test(SafetyMenu::class, ['profileCode' => $bride->profile->code, 'name' => $bride->profile->first_name])
        ->assertSeeHtml('wire:target="block"')->assertSeeHtml('wire:target="report"')->assertSee('Block');
});

it('block from the menu: the block is stored and the page moves to /settings/blocked with a confirmation', function (): void {
    $groom = groom();
    $bride = bride();

    Livewire::actingAs($groom, 'web')->test(SafetyMenu::class, ['profileCode' => $bride->profile->code, 'name' => 'Asha'])
        ->call('block')
        ->assertRedirect(route('member.settings.blocked'));

    expect(Block::query()->where('blocker_profile_id', $groom->profile->id)->exists())->toBeTrue()
        ->and(session('status'))->toContain('You blocked Asha');
});

it('report from the menu: validation errors show; a valid report closes the dialog with a thank-you; a repeat shows a toast', function (): void {
    $groom = groom();
    $bride = bride();
    $menu = Livewire::actingAs($groom, 'web')->test(SafetyMenu::class, ['profileCode' => $bride->profile->code, 'name' => 'Asha']);

    $menu->set('reportReason', 'OTHER')->call('report')->assertHasErrors(['details']);
    $menu->set('reportReason', 'FAKE_PROFILE')->call('report')
        ->assertHasNoErrors()->assertDispatched('close-modal', name: 'report-profile')->assertDispatched('toast', type: 'success');
    $menu->set('reportReason', 'FAKE_PROFILE')->call('report')->assertDispatched('toast', type: 'danger');

    expect(Report::query()->count())->toBe(1);
});

it('security: the profile code is locked; a code the member can’t see is a 404 when acting', function (): void {
    $groom = groom();
    $bride = bride();
    $menu = Livewire::actingAs($groom, 'web')->test(SafetyMenu::class, ['profileCode' => $bride->profile->code, 'name' => 'Asha']);

    expect(fn () => $menu->set('profileCode', groom('+919800000470')->profile->code))->toThrow(CannotUpdateLockedPropertyException::class);

    Livewire::actingAs($groom, 'web')->test(SafetyMenu::class, ['profileCode' => groom('+919800000471')->profile->code, 'name' => 'X'])
        ->call('block')->assertNotFound();
    expect(Block::query()->count())->toBe(0);
});

it('live (block effects on both sides): the blocked member’s open profile page and chat thread leave when `.connections.changed` arrives', function (): void {
    [$groom, $bride] = chatConnected();
    $profilePage = Livewire::actingAs($bride, 'web')->test(Show::class, ['profile' => $groom->profile->code]);
    $thread = Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code]);
    expect($profilePage->instance()->getListeners())->toHaveKey('echo-private:App.Models.User.'.$bride->id.',.connections.changed');

    app(BlockProfile::class)->handle($groom, $bride->profile);

    $profilePage->call('recheck')->assertRedirect(route('member.dashboard'));
    $thread->call('recheck')->assertRedirect(route('member.messages'));
});

it('live: the lists listen for `.connections.changed`', function (string $component): void {
    $groom = groom();
    $instance = Livewire::actingAs($groom, 'web')->test($component)->instance();

    expect(array_keys($instance->getListeners()))->toContain('echo-private:App.Models.User.'.$groom->id.',.connections.changed');
})->with([
    App\Livewire\Member\Engagement\Likes::class,
    App\Livewire\Member\Engagement\Favorites::class,
    App\Livewire\Member\Engagement\Interests::class,
    App\Livewire\Member\Chat\Inbox::class,
]);

it('/settings/blocked: my blocks and ignores only (never who blocked me); unblock / show again work; empty states', function (): void {
    $groom = groom();
    $bride = bride();
    $other = bride('+919800000472');
    app(BlockProfile::class)->handle($groom, $bride->profile);
    app(IgnoreProfile::class)->handle($groom, $other->profile);
    app(BlockProfile::class)->handle(bride('+919800000473'), $groom->profile);   // someone blocked ME — not listed

    $page = Livewire::actingAs($groom, 'web')->test(BlockedProfiles::class)
        ->assertSee($bride->profile->code)->assertSee($other->profile->code)->assertDontSee('OPM'.'none')
        ->assertSeeHtml('wire:target="unblock(&#039;'.$bride->profile->code.'&#039;)"');
    expect(substr_count($page->html(), 'blocked-OPM'))->toBe(1);

    $page->call('unblock', $bride->profile->code)->assertDispatched('toast', type: 'success')
        ->call('unignore', $other->profile->code)->assertDispatched('toast', type: 'success')
        ->assertSee('You haven’t blocked anyone')->assertSee('You haven’t ignored anyone');
    expect(Block::query()->where('blocker_profile_id', $groom->profile->id)->count())->toBe(0)->and(Ignore::query()->count())->toBe(0);

    // A tampered code (not on my list) changes nothing.
    $page->call('unblock', 'OPM999999')->assertDispatched('toast', type: 'danger');
});

it('/settings/blocked: Report on a blocked member files a report (block, then report); a code not on my list is refused', function (): void {
    $groom = groom();
    $bride = bride();
    app(BlockProfile::class)->handle($groom, $bride->profile);

    $page = Livewire::actingAs($groom, 'web')->test(BlockedProfiles::class)
        ->call('openReport', $bride->profile->code)->assertDispatched('open-modal', name: 'report-blocked')
        ->set('reportReason', 'OTHER')->call('report')->assertHasErrors(['details'])
        ->set('reportReason', 'ABUSIVE_MESSAGES')->call('report')->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success');
    expect(Report::query()->sole()->reported_profile_id)->toBe($bride->profile->id);

    $page->call('openReport', groom('+919800000475')->profile->code)->set('reportReason', 'SPAM')->call('report')
        ->assertDispatched('toast', type: 'danger');
    expect(Report::query()->count())->toBe(1);
});

it('/safety is public: tips, how to report, emergency numbers', function (): void {
    $this->get(memberUrl('/safety'))->assertOk()->assertSee('Never send money')->assertSee('1930')->assertSee('How to block or report someone');
});

it('/contact: the form opens a ticket and shows its number; a filled honeypot stores nothing; errors show inline', function (): void {
    Livewire::test(ContactForm::class)
        ->set('firstName', 'Asha')->set('email', 'not-mail')->set('category', 'ACCOUNT')->set('message', 'short')
        ->call('send')->assertHasErrors(['email', 'message']);

    $form = Livewire::test(ContactForm::class)
        ->set('firstName', 'Asha')->set('lastName', 'Menon')->set('email', 'asha@example.com')->set('category', 'ACCOUNT')
        ->set('message', 'My OTP does not arrive on my phone.')->call('send')->assertHasNoErrors();
    $ticket = SupportTicket::query()->sole();
    $form->assertSet('sentCode', $ticket->code)->assertSee($ticket->code);

    Livewire::test(ContactForm::class)->set('website', 'http://spam.example')->set('firstName', 'Bot')
        ->set('email', 'bot@example.com')->set('category', 'OTHER')->set('message', 'Buy cheap things now!!')->call('send');
    expect(SupportTicket::query()->count())->toBe(1);
});

it('/help: a member sends a request and sees only their own; /help/{ticket} replies; another member’s code is a 404', function (): void {
    $groom = groom();
    SupportTicket::factory()->forMember(groom('+919800000474'))->create(['subject' => 'Someone else’s problem']);

    Livewire::actingAs($groom, 'web')->test(Help::class)
        ->assertSee('No requests yet')
        ->set('category', 'PROFILE')->set('subject', 'Change my photo')->set('message', 'How do I change my main photo please?')
        ->call('send')->assertHasNoErrors()->assertDispatched('toast', type: 'success')
        ->assertSee('Change my photo')->assertDontSee('Someone else’s problem');

    $mine = SupportTicket::query()->where('user_id', $groom->id)->sole();
    Livewire::actingAs($groom, 'web')->test(TicketThread::class, ['ticket' => $mine->code])
        ->set('reply', 'Any update?')->call('send')->assertHasNoErrors()->assertSee('Any update?');

    $theirs = SupportTicket::query()->where('user_id', '!=', $groom->id)->sole();
    Livewire::actingAs($groom, 'web')->test(TicketThread::class, ['ticket' => $theirs->code])->assertNotFound();
});
