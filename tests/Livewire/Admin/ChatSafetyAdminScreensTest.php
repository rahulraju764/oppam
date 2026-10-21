<?php

declare(strict_types=1);

use App\Actions\Admin\Chat\UpdateSafetyRule;
use App\Domain\Chat\ChatSafetyRules;
use App\Enums\EngagementSignalStatus;
use App\Enums\EngagementSignalType;
use App\Enums\SafetyAction;
use App\Enums\SafetyRule;
use App\Livewire\Admin\Chat\BreakGlass;
use App\Livewire\Admin\Chat\BreakGlassViewer;
use App\Livewire\Admin\Chat\FlagReview;
use App\Livewire\Admin\Chat\Overview;
use App\Livewire\Admin\Chat\Rules;
use App\Livewire\Admin\Chat\Signals;
use App\Livewire\Member\Chat\Thread;
use App\Models\AuditLog;
use App\Models\BreakGlassGrant;
use App\Models\ChatSafetyRule;
use App\Models\EngagementSignal;
use App\Models\MemberRestriction;
use App\Models\MessageFlag;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P4.3b — A13 admin screens: Signals (list, filters, one-click decisions, paused members), Overview
| (metadata only), Rules (editor + dry-run tester), BreakGlass (start) + BreakGlassViewer
| (watermark, time-limited, audited pages), the sidebar entries, and the member composer's
| editable refusal when a BLOCK rule matches.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    fakeMediaDisks();
});

it('Signals: lists waiting signals with counts (no text), filters by kind, and needs chat.view_flagged', function (): void {
    $member = groom();
    $like = EngagementSignal::factory()->on($member->profile)->create(['details' => ['likes' => 50, 'window_minutes' => 10]]);
    $interest = EngagementSignal::factory()->on(bride()->profile, EngagementSignalType::InterestSpam)->create(['details' => ['sent' => 40, 'accepted' => 0, 'window_days' => 7]]);
    test()->actingAs(adminWithRole('moderator'), 'admin');

    Livewire::test(Signals::class)->assertOk()
        ->assertSeeHtml('wire:key="signal-'.$like->id.'"')->assertSeeHtml('wire:key="signal-'.$interest->id.'"')
        ->assertSee('More than 50 likes in 10 minutes')->assertSee('40 interests in 7 days, 0 accepted')
        ->assertSee($member->profile->code)
        ->assertSee('wire:loading', escape: false)
        ->set('type', 'INTEREST_SPAM')->assertDontSeeHtml('wire:key="signal-'.$like->id.'"')
        ->set('type', '')->set('view', 'decided')->assertSee('No signals match these filters.');

    test()->actingAs(adminWithRole('finance'), 'admin');
    Livewire::test(Signals::class)->assertForbidden();
});

it('Signals: a moderator pauses a member from the dialog; the pause shows under "Paused members" and can be lifted', function (): void {
    $member = groom();
    $signal = EngagementSignal::factory()->on($member->profile)->create();
    test()->actingAs(adminWithRole('moderator'), 'admin');

    $screen = Livewire::test(Signals::class)
        ->call('choose', $signal->id, 'signal-rate_limit')->assertDispatched('open-modal', name: 'signal-rate_limit')
        ->set('hours', 168)->set('note', 'Confirmed like spam')
        ->call('decide', 'RATE_LIMIT')->assertDispatched('toast', type: 'success');

    expect($signal->refresh()->status)->toBe(EngagementSignalStatus::Actioned)
        ->and(MemberRestriction::query()->sole()->until->diffInDays(now()->addDays(7), true))->toBeLessThan(1);

    $pause = MemberRestriction::query()->sole();
    $screen->set('view', 'paused')->assertSeeHtml('wire:key="pause-'.$pause->id.'"')
        ->call('choose', $pause->id, 'lift-pause')->set('note', 'Talked to the member')
        ->call('lift')->assertDispatched('toast', type: 'success');
    expect($pause->refresh()->lifted_at)->not->toBeNull();
});

it('Signals: a decision the admin may not take is refused with a toast; a made-up id is 404; an unknown decision is 404', function (): void {
    $signal = EngagementSignal::factory()->on(groom()->profile)->create();
    test()->actingAs(adminWithRole('moderator'), 'admin');

    Livewire::test(Signals::class)->assertDontSee('Suspend member')
        ->set('selected', $signal->id)->set('note', 'Automated spam')
        ->call('decide', 'SUSPEND')->assertDispatched('toast', type: 'danger');
    expect($signal->refresh()->status)->toBe(EngagementSignalStatus::Open);

    Livewire::test(Signals::class)->set('selected', (string) Str::ulid())->call('decide', 'DISMISS')->assertNotFound();
    Livewire::test(Signals::class)->set('selected', $signal->id)->call('decide', 'DELETE_EVERYTHING')->assertNotFound();
});

it('Overview: metadata only — counts and codes, never message text — and one-sided threads past the threshold', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    chatSend($groom, $bride, 'A very private sentence about my family');
    App\Models\ConversationParticipant::query()->where('conversation_id', $thread->id)->where('profile_id', $groom->profile->id)->update(['unanswered_count' => 25]);
    test()->actingAs(adminWithRole('moderator'), 'admin');

    Livewire::test(Overview::class)->assertOk()
        ->assertSee('Top senders (7 days)')->assertSee($groom->profile->code)->assertSee('One-sided conversations')
        ->assertSee('25')->assertDontSee('private sentence')
        ->call('refreshFigures')->assertOk();

    test()->actingAs(adminWithRole('finance'), 'admin');
    Livewire::test(Overview::class)->assertForbidden();
});

it('Rules: everyone with chat.view_flagged sees the rules and can test; only chat.rules.edit edits (and the server re-checks)', function (): void {
    test()->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(Rules::class)->assertOk()
        ->assertSee('Phone numbers')->assertSee('Hide and flag')->assertDontSee('Save rule')
        ->set('sample', 'call me on 98470 12345')->set('position', 'early')->call('test')
        ->assertSet('result.flags', ['Contact details'])->assertSee('The recipient would see:')
        ->set('editing', 'phone')->set('early', 'MASK')->set('later', 'MASK')->set('reason', 'Tuning after review')
        ->call('save')->assertDispatched('toast', type: 'danger');
    expect(ChatSafetyRule::query()->count())->toBe(0);

    test()->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(Rules::class)
        ->call('edit', 'link')->assertSet('early', 'MASK')->assertDispatched('open-modal', name: 'edit-rule')
        ->set('early', 'BLOCK')->set('sample', 'visit www.example.com')->call('testDraft')
        ->assertSet('result.blocked', SafetyRule::Link->refusal())
        ->set('reason', 'Links are only used for scams')->call('save')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
    expect(app(ChatSafetyRules::class)->get(SafetyRule::Link)->early)->toBe(SafetyAction::Block);

    Livewire::test(Rules::class)->call('edit', 'link')->set('pattern', '(a+)+$')->set('reason', 'Tuning after review')
        ->call('save')->assertHasErrors('pattern');
    Livewire::test(Rules::class)->call('edit', 'nonsense')->assertNotFound();
});

it('BreakGlass: super admins only; find a pair by codes (metadata only), open with reason + reference → the viewer', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    chatSend($groom, $bride, 'A very private sentence');
    test()->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(BreakGlass::class)->assertForbidden();

    $admin = adminWithRole('super_admin');
    test()->actingAs($admin, 'admin');
    $screen = Livewire::test(BreakGlass::class)
        ->set('codeA', $groom->profile->code)->set('codeB', 'OPM999999')->call('find')->assertHasErrors('codeB')
        ->set('codeB', strtolower($bride->profile->code))->call('find')->assertHasNoErrors()
        ->assertSet('threadId', $thread->id)->assertSee('Messages')->assertDontSee('private sentence');

    expect(fn () => $screen->set('threadId', (string) Str::ulid()))->toThrow(CannotUpdateLockedPropertyException::class);

    $screen->set('referenceType', 'LEGAL')->set('reference', 'KL/POL/2026/7')->set('reason', 'short')->call('open')->assertHasErrors('reason')
        ->set('reason', 'Police request about a reported marriage fraud')->call('open');
    $grant = BreakGlassGrant::query()->sole();
    $screen->assertRedirect(route('admin.chat.break-glass.read', ['grant' => $grant->id]));
});

it('BreakGlass from a flag review: the pair and the flag reference are filled in; the review page links there for super admins only', function (): void {
    [$groom, $bride] = chatConnected();
    $flag = MessageFlag::query()->where('message_id', chatSend($groom, $bride, 'you are a bastard')->id)->sole();

    test()->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(BreakGlass::class, ['flag' => $flag->id])
        ->assertSet('referenceType', 'FLAG')->assertSet('reference', $flag->id)->assertSet('threadId', $flag->conversation_id);
    Livewire::test(FlagReview::class, ['flag' => $flag->id])->assertSee('Read the whole conversation (break-glass, audited)');

    test()->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(FlagReview::class, ['flag' => $flag->id])->assertDontSee('Read the whole conversation');
});

it('BreakGlassViewer: watermark, audited pages, locked range; another admin\'s grant is 404; an ended grant shows no text', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    chatSend($groom, $bride, 'A very private sentence');
    $admin = adminWithRole('super_admin');
    $grant = app(App\Actions\Admin\Chat\OpenBreakGlass::class)->handle($admin, $thread, 'Police request about a reported marriage fraud', 'LEGAL', 'KL/POL/2026/7');

    test()->actingAs($admin, 'admin');
    $viewer = Livewire::test(BreakGlassViewer::class, ['grant' => $grant->id])->assertOk()
        ->assertSee('A very private sentence')->assertSee($admin->email)->assertSee('Time left');
    expect(AuditLog::query()->where('action', 'chat.break_glass.read')->count())->toBe(1);

    $viewer->call('$refresh');
    expect(AuditLog::query()->where('action', 'chat.break_glass.read')->count())->toBe(1);   // re-rendering the same range isn't a new read

    expect(fn () => $viewer->set('fromId', (string) Str::ulid()))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $viewer->set('grantId', (string) Str::ulid()))->toThrow(CannotUpdateLockedPropertyException::class);

    $viewer->call('end')->assertDispatched('toast', type: 'success')->assertDontSee('A very private sentence')->assertSee('This session has ended');

    test()->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(BreakGlassViewer::class, ['grant' => $grant->id])->assertNotFound();
});

it('the sidebar shows the A13 screens by permission: break-glass to super admins only', function (): void {
    signInAdmin($this, adminWithRole('moderator'));
    $this->get(adminUrl('/chat/signals'))->assertOk()
        ->assertSee('Abuse signals')->assertSee('Conversation overview')->assertSee('Rules &amp; word lists', escape: false)
        ->assertSee('data-queue="chat.signals"', escape: false)->assertDontSee('Break-glass read');
    $this->get(adminUrl('/chat/break-glass'))->assertForbidden();

    signInAdmin($this, adminWithRole('super_admin'));
    $this->get(adminUrl('/chat/rules'))->assertOk()->assertSee('Break-glass read');
});

it('member composer: a message a BLOCK rule refused comes back as "edit", not "retry"', function (): void {
    [$groom, $bride] = chatConnected();
    app(UpdateSafetyRule::class)->handle(adminWithRole('super_admin'), SafetyRule::Link, SafetyRule::Link->defaultPattern(), SafetyAction::Block, SafetyAction::Block, 'Links are only used for scams');
    app(ChatSafetyRules::class)->flush();
    $clientId = (string) Str::uuid();

    test()->actingAs($groom);
    Livewire::test(Thread::class, ['withCode' => $bride->profile->code])
        ->call('send', $clientId, 'see https://example.com')
        ->assertDispatched('chat-send-failed', clientId: $clientId, editable: true)
        ->assertSeeHtml('Edit message');
});
