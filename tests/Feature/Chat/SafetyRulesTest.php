<?php

declare(strict_types=1);

use App\Actions\Admin\Chat\ResetSafetyRule;
use App\Actions\Admin\Chat\TestSafetyRules;
use App\Actions\Admin\Chat\UpdateSafetyRule;
use App\Contracts\ContentSafety;
use App\Data\Chat\SafetyRuleData;
use App\Domain\Chat\ChatSafetyRules;
use App\Domain\Chat\PatternGuard;
use App\Enums\MessageFlagRule;
use App\Enums\SafetyAction;
use App\Enums\SafetyRule;
use App\Events\Chat\MessageSent;
use App\Exceptions\Chat\ChatRefused;
use App\Models\AuditLog;
use App\Models\ChatSafetyRule;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Services\Chat\RuleBasedContentSafety;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| P4.3b — A13 rules editor (PRD A13 "patterns for phone / email / UPI, per-rule action (mask / flag /
| block), dry-run tester"): the defaults are the P4.3a behaviour, overrides change it at once, BLOCK
| refuses the send (decisions 2026-10-18), patterns are checked before they are saved, the tester
| stores nothing.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

function rulesScan(string $body, bool $early = true): App\Data\Chat\SafetyScanData
{
    return app(ContentSafety::class)->scan($body, '01hzzzzzzzzzzzzzzzzzzzzzzz', '01hyyyyyyyyyyyyyyyyyyyyyyy', $early);
}

function rulesUpdate(SafetyRule $rule, SafetyAction $early, SafetyAction $later, ?string $pattern = null, ?App\Models\AdminUser $admin = null): void
{
    app(UpdateSafetyRule::class)->handle($admin ?? adminWithRole('super_admin'), $rule, $pattern ?? $rule->defaultPattern(), $early, $later, 'Tuning after review');
    app(ChatSafetyRules::class)->flush();   // the next request would start without the memo
}

it('every default pattern passes the pattern guard (a guard that refused them would block every edit)', function (SafetyRule $rule): void {
    expect(app(PatternGuard::class)->problem((string) $rule->defaultPattern()))->toBeNull();
})->with(array_filter(SafetyRule::cases(), fn (SafetyRule $rule): bool => $rule->hasPattern()));

it('the pattern guard refuses broken, empty-matching, too long and catastrophically slow patterns', function (string $pattern): void {
    expect(app(PatternGuard::class)->problem($pattern))->not->toBeNull();
})->with([
    'empty' => [''],
    'does not compile' => ['(abc'],
    'unescaped slash' => ['https://x'],
    'matches empty text' => ['\d*'],
    'too long' => [str_repeat('a', PatternGuard::MAX_LENGTH + 1)],
    'nested quantifier' => ['(a+)+$'],
    'alternation blow-up' => ['(a|aa)+$'],
]);

it('with no override the rules are the P4.3a defaults', function (): void {
    $rules = app(ChatSafetyRules::class)->all();

    expect(array_keys($rules))->toBe(array_map(fn (SafetyRule $r): string => $r->value, SafetyRule::cases()))
        ->and($rules['phone']->early)->toBe(SafetyAction::Mask)->and($rules['phone']->later)->toBe(SafetyAction::Off)
        ->and($rules['link']->later)->toBe(SafetyAction::Flag)
        ->and($rules['money']->early)->toBe(SafetyAction::Flag)
        ->and(collect($rules)->every(fn (SafetyRuleData $r): bool => ! $r->customised))->toBeTrue();
});

it('A13: an override changes what happens at once — phone numbers hidden later in the thread too', function (): void {
    expect(rulesScan('call 98470 12345', early: false)->findings)->toBe([]);

    rulesUpdate(SafetyRule::Phone, SafetyAction::Mask, SafetyAction::Mask);

    $scan = rulesScan('call 98470 12345', early: false);
    expect($scan->body)->toBe('call '.RuleBasedContentSafety::MASK)
        ->and($scan->findings[0]->rule)->toBe(MessageFlagRule::ContactInfo)
        ->and(app(ChatSafetyRules::class)->get(SafetyRule::Phone)->customised)->toBeTrue();
});

it('A13: FLAG keeps the text but flags it; OFF ignores the rule', function (): void {
    rulesUpdate(SafetyRule::Email, SafetyAction::Flag, SafetyAction::Off);
    $flagged = rulesScan('write to anjali.k@gmail.com');
    expect($flagged->body)->toBe('write to anjali.k@gmail.com')->and($flagged->masked)->toBeFalse()
        ->and($flagged->findings[0]->excerpt)->toBe('anjali.k@gmail.com');

    rulesUpdate(SafetyRule::Money, SafetyAction::Off, SafetyAction::Off);
    expect(rulesScan('please send money for the ticket', early: false)->findings)->toBe([]);
});

it('A13: an edited pattern is used — a custom phone pattern catches 8-digit landlines', function (): void {
    rulesUpdate(SafetyRule::Phone, SafetyAction::Mask, SafetyAction::Off, '\b\d{4}\s?\d{4}\b');

    expect(rulesScan('landline 0484 2345')->body)->toBe('landline '.RuleBasedContentSafety::MASK);
});

it('A13: abusive words set to "hide and flag" are masked for the recipient', function (): void {
    rulesUpdate(SafetyRule::Profanity, SafetyAction::Mask, SafetyAction::Mask);

    $scan = rulesScan('you are a BASTARD, really', early: false);
    expect($scan->body)->toBe('you are a '.RuleBasedContentSafety::MASK.', really')
        ->and($scan->findings[0]->rule)->toBe(MessageFlagRule::Profanity);
});

it('decisions 2026-10-18: a BLOCK rule refuses the send — 422, nothing stored, no flag, nothing broadcast', function (): void {
    [$groom, $bride, $thread] = chatConnected();
    rulesUpdate(SafetyRule::Link, SafetyAction::Block, SafetyAction::Block);
    Event::fake([MessageSent::class]);
    $before = Message::query()->where('conversation_id', $thread->id)->count();

    try {
        chatSend($groom, $bride, 'see my photos at https://example.com/me');
        $this->fail('The message was sent.');
    } catch (ChatRefused $refused) {
        expect($refused->reason)->toBe(ChatRefused::BLOCKED)->and($refused->getStatusCode())->toBe(422)
            ->and($refused->getMessage())->toContain('web address')->not->toContain('https');
    }

    expect(Message::query()->where('conversation_id', $thread->id)->count())->toBe($before)
        ->and(MessageFlag::query()->count())->toBe(0);
    Event::assertNotDispatched(MessageSent::class);

    // The edited text goes through.
    expect(chatSend($groom, $bride, 'see my photos on the app')->body)->toBe('see my photos on the app');
});

it('A13: the repeated-message rule can block too (and can never "hide")', function (): void {
    rulesUpdate(SafetyRule::Repetition, SafetyAction::Block, SafetyAction::Block);
    $sender = groom();
    $spam = 'Hello dear, I liked your profile very much, please reply soon';
    foreach (['+919800000701', '+919800000702'] as $i => $phone) {
        [$sender, $member] = chatPairOf($sender->refresh(), bride($phone), $i === 0 ? App\Enums\PlanCode::Gold : null);
        chatSend($sender, $member, $spam);
    }
    [$sender, $third] = chatPairOf($sender->refresh(), bride('+919800000703'), null);

    expect(fn () => chatSend($sender, $third, $spam))->toThrow(ChatRefused::class);
    expect(fn () => app(UpdateSafetyRule::class)->handle(adminWithRole('super_admin'), SafetyRule::Repetition, null, SafetyAction::Mask, SafetyAction::Flag, 'Tuning after review'))
        ->toThrow(ValidationException::class);
});

it('a rule whose pattern fails at run time keeps the text as typed and flags the message for a person', function (): void {
    // Written past the guard (as a corrupted row would be), run with the JIT off and a tiny backtrack limit.
    (new ChatSafetyRule)->forceFill(['key' => 'link', 'pattern' => '(a+)+$', 'early_action' => 'MASK', 'later_action' => 'FLAG'])->save();
    app(ChatSafetyRules::class)->flush();
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1000');

    try {
        $scan = rulesScan(str_repeat('a', 40).'!');
    } finally {
        ini_set('pcre.jit', (string) $jit);
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    expect($scan->body)->toBe(str_repeat('a', 40).'!')
        ->and(collect($scan->findings)->pluck('rule')->all())->toContain(MessageFlagRule::ExternalLink);
});

it('security: editing a rule needs chat.rules.edit and a reason; actions must be allowed; it is audited with before / after', function (): void {
    $update = app(UpdateSafetyRule::class);

    expect(fn () => $update->handle(adminWithRole('moderator'), SafetyRule::Phone, null, SafetyAction::Mask, SafetyAction::Mask, 'Tuning after review'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $update->handle(adminWithRole('super_admin'), SafetyRule::Phone, SafetyRule::Phone->defaultPattern(), SafetyAction::Mask, SafetyAction::Mask, 'ok'))
        ->toThrow(ValidationException::class)
        ->and(fn () => $update->handle(adminWithRole('super_admin'), SafetyRule::Phone, '(a+)+$', SafetyAction::Mask, SafetyAction::Mask, 'Tuning after review'))
        ->toThrow(ValidationException::class);
    expect(ChatSafetyRule::query()->count())->toBe(0);

    rulesUpdate(SafetyRule::Phone, SafetyAction::Mask, SafetyAction::Flag);
    $log = AuditLog::query()->where('action', 'chat.rule.updated')->sole();
    expect($log->subject_label)->toBe('phone')->and($log->before)->toMatchArray(['later' => 'OFF'])
        ->and($log->after)->toMatchArray(['later' => 'FLAG'])->and($log->reason)->toBe('Tuning after review');
});

it('reset puts the default back, audited; resetting a default rule changes nothing', function (): void {
    rulesUpdate(SafetyRule::Phone, SafetyAction::Mask, SafetyAction::Mask);
    $reset = app(ResetSafetyRule::class);
    $admin = adminWithRole('super_admin');

    expect(fn () => $reset->handle(adminWithRole('moderator'), SafetyRule::Phone, 'Back to default'))->toThrow(AuthorizationException::class)
        ->and($reset->handle($admin, SafetyRule::Phone, 'Back to default'))->toBeTrue()
        ->and($reset->handle($admin, SafetyRule::Phone, 'Back to default'))->toBeFalse();
    app(ChatSafetyRules::class)->flush();

    expect(app(ChatSafetyRules::class)->get(SafetyRule::Phone)->later)->toBe(SafetyAction::Off)
        ->and(AuditLog::query()->where('action', 'chat.rule.reset')->count())->toBe(1);
});

it('the dry-run tester uses the draft, stores nothing, needs chat.view_flagged and refuses a slow draft', function (): void {
    $tester = app(TestSafetyRules::class);
    $moderator = adminWithRole('moderator');
    $draft = new SafetyRuleData(SafetyRule::Link, SafetyRule::Link->defaultPattern(), SafetyAction::Block, SafetyAction::Block);

    $saved = $tester->handle($moderator, 'see https://example.com', true);
    $drafted = $tester->handle($moderator, 'see https://example.com', true, $draft);

    expect($saved->blockedBy)->toBeNull()->and($saved->body)->toContain(RuleBasedContentSafety::MASK)
        ->and($drafted->blockedBy)->toBe(SafetyRule::Link)
        ->and($drafted->hits[0]->matches)->toBe(['https://example.com'])
        ->and(ChatSafetyRule::query()->count())->toBe(0)
        ->and(MessageFlag::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    expect(fn () => $tester->handle(adminWithRole('finance'), 'hello', true))->toThrow(AuthorizationException::class)
        ->and(fn () => $tester->handle($moderator, 'hello', true, new SafetyRuleData(SafetyRule::Link, '(a+)+$', SafetyAction::Flag, SafetyAction::Flag)))->toThrow(ValidationException::class)
        ->and(fn () => $tester->handle($moderator, '   ', true))->toThrow(ValidationException::class);
});
