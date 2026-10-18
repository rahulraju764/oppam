<?php

declare(strict_types=1);

use App\Enums\ConversationStatus;
use App\Livewire\Member\Chat\Thread;
use App\Models\MessageFlag;
use App\Models\Report;
use App\Services\Chat\RuleBasedContentSafety;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| P4.3a — the member's side of chat safety (PRD M09, R-M07-4/5): the report dialog in the thread,
| the "contact details hidden" notice, and the live freeze listener.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

it('M09: the report dialog lists only the other member\'s messages; sending it files the report and thanks the member', function (): void {
    [$groom, $bride] = chatConnected(App\Enums\PlanCode::Gold, App\Enums\PlanCode::Gold);
    $his = chatSend($groom, $bride, 'Send me your photos now');
    chatSend($bride, $groom, 'My own words here');

    Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code])
        ->assertSeeHtml('id="report-'.$his->id.'"')
        ->assertSee('Report '.$groom->profile->first_name)
        ->assertSeeHtml('wire:target="report"')
        ->set('reportReason', 'ABUSIVE_MESSAGES')
        ->set('reportMessages', [$his->id])
        ->set('reportDetails', 'He keeps asking')
        ->call('report')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success')
        ->assertDispatched('close-modal', name: 'report-chat')
        ->assertSet('reportMessages', [])->assertSet('reportReason', '');

    $report = Report::query()->sole();
    expect($report->details)->toBe('He keeps asking')->and(MessageFlag::query()->sole()->message_id)->toBe($his->id);
});

it('M09: errors show on the dialog (no reason, no message, a tampered id); a second report is refused with a toast', function (): void {
    [$groom, $bride] = chatConnected(App\Enums\PlanCode::Gold, App\Enums\PlanCode::Gold);
    $his = chatSend($groom, $bride, 'Hello there');
    $mine = chatSend($bride, $groom, 'Hi');
    $thread = Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code]);

    $thread->call('report')->assertHasErrors(['reason', 'messages']);
    $thread->set('reportReason', 'SPAM')->set('reportMessages', [$mine->id])->call('report')->assertHasErrors(['messages']);
    expect(Report::query()->count())->toBe(0);

    $thread->set('reportMessages', [$his->id])->call('report')->assertHasNoErrors();
    $thread->set('reportReason', 'SPAM')->set('reportMessages', [$his->id])->call('report')->assertDispatched('toast', type: 'danger');
    expect(Report::query()->count())->toBe(1);
});

it('an admin support session has no report button and can\'t report', function (): void {
    [$groom, $bride] = chatConnected();
    $his = chatSend($groom, $bride, 'Hello there');
    chatImpersonate($bride);

    Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code])
        ->assertDontSee('Send report')
        ->set('reportReason', 'SPAM')->set('reportMessages', [$his->id])
        ->call('report')->assertDispatched('toast', type: 'danger');

    expect(Report::query()->count())->toBe(0);
});

it('R-M07-4: a sender whose first message had contact details is told they were hidden', function (): void {
    [$groom, $bride] = chatConnected();

    Livewire::actingAs($groom, 'web')->test(Thread::class, ['withCode' => $bride->profile->code])
        ->call('send', (string) Illuminate\Support\Str::uuid(), 'my number 98470 12345')
        ->assertDispatched('toast', type: 'info')
        ->assertSee(RuleBasedContentSafety::MASK)->assertDontSee('98470 12345');
});

it('R-M07-5: the thread listens for .conversation.frozen; a frozen thread shows its messages and no composer', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    chatSend($groom, $bride, 'Hello there');

    $component = Livewire::actingAs($bride, 'web')->test(Thread::class, ['withCode' => $groom->profile->code]);
    expect($component->instance()->getListeners())->toHaveKey('echo-private:chat.'.$thread->id.',.conversation.frozen');

    $thread->forceFill(['status' => ConversationStatus::Frozen])->save();
    Livewire::actingAs($groom, 'web')->test(Thread::class, ['withCode' => $bride->profile->code])
        ->assertSee('Hello there')->assertSee('This conversation is closed for new messages.')->assertDontSeeHtml('id="msgText"');
});

it('M07: a muted conversation shows the muted icon in the inbox (P4.2 carry-over)', function (): void {
    [$groom, $bride] = chatConnected();
    app(App\Actions\Chat\SetConversationMuted::class)->handle($bride, $groom->profile, true);

    Livewire::actingAs($bride, 'web')->test(App\Livewire\Member\Chat\Inbox::class)
        ->assertSeeHtml('msg-thread-muted')->assertSee('(muted)');
    Livewire::actingAs($groom, 'web')->test(App\Livewire\Member\Chat\Inbox::class)
        ->assertDontSeeHtml('msg-thread-muted');
});
