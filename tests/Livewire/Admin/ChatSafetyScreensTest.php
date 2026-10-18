<?php

declare(strict_types=1);

use App\Enums\MessageFlagDecision;
use App\Enums\MessageFlagStatus;
use App\Livewire\Admin\Chat\FlaggedQueue;
use App\Livewire\Admin\Chat\FlagReview;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\MessageFlag;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P4.3a — A13 screens: the flagged queue (metadata only, filters, live), the review page (text hidden
| until the audited reveal, ±3 context, decisions with their permissions, locked props), the flagged
| photo route, and the sidebar entry.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    fakeMediaDisks();
});

function asChatAdmin(AdminUser $admin): void
{
    test()->actingAs($admin, 'admin');
}

it('the queue lists waiting flags as metadata only — never message text — and filters by view, reason and source', function (): void {
    [$groom, $bride] = chatConnected();
    $abusive = MessageFlag::query()->where('message_id', chatSend($groom, $bride, 'you are a bastard')->id)->sole();
    $contact = MessageFlag::query()->where('message_id', chatSend($groom, $bride, 'call me 98470 12345')->id)->sole();
    asChatAdmin(adminWithRole('moderator'));
    $row = fn (MessageFlag $flag): string => 'wire:key="flag-'.$flag->id.'"';

    Livewire::test(FlaggedQueue::class)
        ->assertOk()
        ->assertSeeHtml($row($abusive))->assertSeeHtml($row($contact))->assertSee($groom->profile->code)
        ->assertDontSee('bastard')->assertDontSee('98470')
        ->assertSee('wire:loading', escape: false)
        ->set('rule', 'PROFANITY')->assertSeeHtml($row($abusive))->assertDontSeeHtml($row($contact))
        ->set('rule', "x' OR 1=1")->assertSeeHtml($row($contact))       // an unknown value is no filter
        ->set('view', 'decided')->assertSee('No flags match these filters.');
});

it('the queue needs chat.view_flagged and listens on admin.queues', function (): void {
    asChatAdmin(adminWithRole('finance'));
    Livewire::test(FlaggedQueue::class)->assertForbidden();

    asChatAdmin(adminWithRole('moderator'));
    expect(Livewire::test(FlaggedQueue::class)->instance()->getListeners())->toHaveKey('echo-private:admin.queues,.queue.count');
});

it('review: the text is hidden until "Reveal message"; the reveal is audited and shows only ±3 messages', function (): void {
    [$groom, $bride] = chatConnected(App\Enums\PlanCode::Gold, App\Enums\PlanCode::Gold);
    foreach (range(1, 8) as $i) {
        chatSend($i % 2 ? $groom : $bride, $i % 2 ? $bride : $groom, $i === 5 ? 'you are a bastard' : "Line number {$i} here");
    }
    $flag = MessageFlag::query()->sole();
    asChatAdmin(adminWithRole('moderator'));

    $review = Livewire::test(FlagReview::class, ['flag' => $flag->id])
        ->assertOk()->assertSee('Reveal message')->assertDontSee('bastard')->assertDontSee('Line number')
        ->assertSee('Reveal the message before deciding.')
        ->call('reveal')
        ->assertSee('bastard')->assertSee('Line number 2 here')->assertSee('Line number 8 here')
        ->assertDontSee('Line number 1 here')
        ->assertSee('Dismiss')->assertSee('Freeze conversation')->assertDontSee('Suspend sender');   // a moderator lacks members.suspend

    expect(AuditLog::query()->where('action', 'chat.flag.revealed')->count())->toBe(1);
    $review->call('decide', 'DISMISS')->assertDispatched('toast');
    expect($flag->refresh()->status)->toBe(MessageFlagStatus::Dismissed)
        ->and(AuditLog::query()->where('action', 'chat.flag.revealed')->count())->toBe(1);   // re-renders don't re-audit
});

it('review security: locked props can\'t be tampered with; a made-up or malformed flag is 404; an unknown decision is 404; no permission → forbidden', function (): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride, 'you are a bastard');
    $flag = MessageFlag::query()->sole();

    asChatAdmin(adminWithRole('moderator'));
    expect(fn () => Livewire::test(FlagReview::class, ['flag' => $flag->id])->set('revealed', true))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => Livewire::test(FlagReview::class, ['flag' => $flag->id])->set('flagId', '01hzzzzzzzzzzzzzzzzzzzzzzz'))->toThrow(CannotUpdateLockedPropertyException::class);
    Livewire::test(FlagReview::class, ['flag' => '01hzzzzzzzzzzzzzzzzzzzzzzz'])->assertNotFound();
    Livewire::test(FlagReview::class, ['flag' => 'nope'])->assertNotFound();
    Livewire::test(FlagReview::class, ['flag' => $flag->id])->call('reveal')->call('decide', 'DELETE_EVERYTHING')->assertNotFound();

    asChatAdmin(adminWithRole('support'));
    Livewire::test(FlagReview::class, ['flag' => $flag->id])->assertForbidden();
});

it('review: a decision the admin may not take is refused with a toast, nothing changes', function (): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride, 'you are a bastard');
    $flag = MessageFlag::query()->sole();
    asChatAdmin(adminWithRole('moderator'));

    Livewire::test(FlagReview::class, ['flag' => $flag->id])->call('reveal')
        ->set('note', 'Abusive to several members')
        ->call('decide', MessageFlagDecision::SuspendSender->value)
        ->assertDispatched('toast', type: 'danger');

    expect($flag->refresh()->status)->toBe(MessageFlagStatus::Pending)->and($groom->refresh()->status)->toBe(App\Enums\UserStatus::Active);
});

it('the flagged photo route: only after this admin\'s reveal, only the flagged message, only with chat.view_flagged', function (): void {
    [$groom, $bride] = chatConnected();
    $photo = app(App\Actions\Chat\SendMessage::class)->image($groom, $bride->profile, gradientPhoto(), (string) Illuminate\Support\Str::uuid());
    $report = app(App\Actions\Chat\ReportChatMessages::class)->handle($bride, $groom->profile, 'INAPPROPRIATE_PHOTOS', null, [$photo->id]);
    $flag = MessageFlag::query()->where('report_id', $report->id)->sole();
    $moderator = adminWithRole('moderator');
    $url = adminUrl('/chat/flags/'.$flag->id.'/image');

    signInAdmin($this, $moderator);
    $this->get($url)->assertNotFound();                                   // not revealed yet

    $this->withSession([FlagReview::REVEALED_SESSION.$flag->id => now()->getTimestamp()])->get($url)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $this->withSession([FlagReview::REVEALED_SESSION.$flag->id => now()->subMinutes(31)->getTimestamp()])->get($url)->assertNotFound();

    signInAdmin($this, adminWithRole('finance'));
    $this->withSession([FlagReview::REVEALED_SESSION.$flag->id => now()->getTimestamp()])->get($url)->assertForbidden();
});

it('the sidebar shows "Flagged messages" with its waiting count to chat moderators only', function (): void {
    [$groom, $bride] = chatConnected();
    chatSend($groom, $bride, 'you are a bastard');

    $moderator = adminWithRole('moderator');
    signInAdmin($this, $moderator);
    $this->get(adminUrl('/chat/flags'))->assertOk()->assertSee('Flagged messages')->assertSee('data-queue="chat.flags"', escape: false);

    $finance = adminWithRole('finance');
    signInAdmin($this, $finance);
    $this->get(adminUrl('/'))->assertOk()->assertDontSee('Flagged messages');
});

it('review: after the reveal a moderator sees how many of the sender\'s threads the reports froze and opens them all at once', function (): void {
    $sender = groom();
    $reporters = [];
    foreach (['+919800000601', '+919800000602', '+919800000603'] as $i => $phone) {
        [, $reporter] = chatPairOf($sender->refresh(), bride($phone), $i === 0 ? App\Enums\PlanCode::Gold : null);
        chatSend($sender->refresh(), $reporter, 'Message number '.$i);
        $reporters[] = $reporter;
    }
    foreach ($reporters as $reporter) {
        $thread = App\Models\Conversation::query()->where('pair_key', App\Models\Conversation::pairKey($reporter->profile->id, $sender->profile->id))->value('id');
        app(App\Actions\Chat\ReportChatMessages::class)->handle($reporter, $sender->profile, 'SPAM', null,
            App\Models\Message::query()->where('conversation_id', $thread)->where('sender_profile_id', $sender->profile->id)->pluck('id')->all());
    }
    $flag = MessageFlag::query()->firstOrFail();
    asChatAdmin(adminWithRole('moderator'));

    Livewire::test(FlagReview::class, ['flag' => $flag->id])
        ->assertDontSee('Open them all again')                 // nothing shown before the reveal
        ->call('reveal')
        ->assertSee('Open them all again')->assertSee('3 of this member')->assertSee('conversations were frozen automatically after several reports.')
        ->set('freezeReason', 'Reviewed: the reports were false')
        ->call('reopenReportFrozen')
        ->assertDispatched('toast', type: 'success')
        ->assertDontSee('Open them all again');

    expect(App\Models\Conversation::query()->where('status', App\Enums\ConversationStatus::Frozen->value)->count())->toBe(0);
});
