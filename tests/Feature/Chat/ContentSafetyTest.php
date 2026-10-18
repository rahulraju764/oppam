<?php

declare(strict_types=1);

use App\Contracts\ContentSafety;
use App\Enums\MessageFlagRule;
use App\Enums\MessageFlagSource;
use App\Enums\MessageFlagStatus;
use App\Enums\PlanCode;
use App\Enums\SettingKey;
use App\Events\Admin\AdminQueueCountChanged;
use App\Events\Chat\MessageSent;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Models\Setting;
use App\Services\Chat\RuleBasedContentSafety;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| P4.3a — ContentSafety (PRD M07 send flow, R-M07-4, A13): masking in a thread's first messages,
| flags for contact details, links, money / UPI / bank requests, abusive words and repeated
| messages; SendMessage stores and broadcasts the masked text and fills the A13 queue.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

function safetyScan(string $body, bool $early = true): App\Data\Chat\SafetyScanData
{
    return app(ContentSafety::class)->scan($body, '01hzzzzzzzzzzzzzzzzzzzzzzz', '01hyyyyyyyyyyyyyyyyyyyyyyy', $early);
}

/** @return list<string> */
function safetyRules(App\Data\Chat\SafetyScanData $scan): array
{
    return array_map(fn ($finding): string => $finding->rule->value, $scan->findings);
}

it('R-M07-4: in the first messages contact details, links and UPI ids are masked and flagged', function (string $body, string $hidden, MessageFlagRule $rule): void {
    $scan = safetyScan($body);

    expect($scan->body)->not->toContain($hidden)->toContain(RuleBasedContentSafety::MASK)
        ->and($scan->masked)->toBeTrue()
        ->and(safetyRules($scan))->toContain($rule->value)
        ->and(collect($scan->findings)->firstWhere('rule', $rule)?->excerpt)->toContain($hidden);
})->with([
    'phone' => ['Call me on 98470 12345 tonight', '98470 12345', MessageFlagRule::ContactInfo],
    'phone +91' => ['my number is +91-9847012345', '9847012345', MessageFlagRule::ContactInfo],
    'email' => ['write to anjali.k@gmail.com', 'anjali.k@gmail.com', MessageFlagRule::ContactInfo],
    'handle' => ['follow me @anjali_kochi', '@anjali_kochi', MessageFlagRule::ContactInfo],
    'link' => ['see https://example.com/me', 'https://example.com/me', MessageFlagRule::ExternalLink],
    'bare domain' => ['my site is anjali.in', 'anjali.in', MessageFlagRule::ExternalLink],
    'upi' => ['pay to anjali@okaxis', 'anjali@okaxis', MessageFlagRule::MoneyRequest],
]);

it('later in the thread contact details may be shared; links and UPI ids are flagged but not masked', function (): void {
    $phone = safetyScan('My number is 98470 12345', early: false);
    expect($phone->body)->toBe('My number is 98470 12345')->and($phone->findings)->toBe([]);

    $link = safetyScan('see https://example.com/me', early: false);
    expect($link->body)->toBe('see https://example.com/me')->and(safetyRules($link))->toBe([MessageFlagRule::ExternalLink->value]);

    $upi = safetyScan('pay to anjali@okaxis', early: false);
    expect($upi->masked)->toBeFalse()->and(safetyRules($upi))->toBe([MessageFlagRule::MoneyRequest->value]);

    // An email address later in the thread is not a UPI id.
    expect(safetyScan('mail anjali.k@gmail.com', early: false)->findings)->toBe([]);
});

it('A13: money requests and abusive words are flagged anywhere in the thread, never masked', function (string $body, MessageFlagRule $rule): void {
    $scan = safetyScan($body, early: false);

    expect($scan->body)->toBe($body)->and(safetyRules($scan))->toContain($rule->value);
})->with([
    'rupees' => ['Please send me ₹5000 for the ticket', MessageFlagRule::MoneyRequest],
    'rs' => ['need Rs. 2000 urgently', MessageFlagRule::MoneyRequest],
    'phrase' => ['Can you transfer some money to me', MessageFlagRule::MoneyRequest],
    'ifsc' => ['IFSC SBIN0001234 is my branch', MessageFlagRule::MoneyRequest],
    'gift card' => ['buy me a gift card please', MessageFlagRule::MoneyRequest],
    'profanity' => ['you are a bastard', MessageFlagRule::Profanity],
    'profanity any case' => ['SHIT happens', MessageFlagRule::Profanity],
]);

it('ordinary messages pass untouched: no mask, no flag', function (string $body): void {
    $scan = safetyScan($body);

    expect($scan->body)->toBe($body)->and($scan->findings)->toBe([])->and($scan->masked)->toBeFalse();
})->with([
    'Namaskaram! How was your day?',
    'I work at Technopark, Thiruvananthapuram.',
    'My family is from Thrissur, 3 brothers.',
    'Good morning ☀️',
    'Shall we talk on Sunday at 6.30?',
]);

it('SendMessage stores and broadcasts the MASKED text, flags the message and raises the admin badge after commit', function (): void {
    Event::fake([MessageSent::class, AdminQueueCountChanged::class]);
    [$groom, $bride] = chatConnected();

    $message = chatSend($groom, $bride, 'Hi! my number is 98470 12345');

    expect($message->refresh()->body)->toBe('Hi! my number is '.RuleBasedContentSafety::MASK)
        ->and($message->is_flagged)->toBeTrue();
    $flag = MessageFlag::query()->sole();
    expect($flag->rule)->toBe(MessageFlagRule::ContactInfo)->and($flag->source)->toBe(MessageFlagSource::Auto)
        ->and($flag->status)->toBe(MessageFlagStatus::Pending)->and($flag->excerpt)->toBe('98470 12345')
        ->and($flag->sender_profile_id)->toBe($groom->profile->id)
        // the excerpt is encrypted at rest
        ->and((string) Illuminate\Support\Facades\DB::table('message_flags')->value('excerpt'))->not->toContain('98470');

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e): bool => ! str_contains($e->body, '98470'));
    Event::assertDispatched(AdminQueueCountChanged::class, fn (AdminQueueCountChanged $e): bool => $e->queueKey === MessageFlag::QUEUE_KEY && $e->count === 1);
});

it('R-M07-4: masking stops after chat.safety_first_messages in the thread (both members count)', function (): void {
    Setting::factory()->keyed(SettingKey::ChatSafetyFirstMessages, 3)->create();
    Settings::flush();
    [$groom, $bride] = chatConnected(PlanCode::Gold, PlanCode::Gold);

    chatSend($groom, $bride, 'Hello');
    chatSend($bride, $groom, 'Hi');
    $third = chatSend($groom, $bride, 'call 98470 12345');    // the 3rd message: still early
    $fourth = chatSend($bride, $groom, 'mine is 98470 54321'); // the 4th: may share now

    expect($third->refresh()->body)->toContain(RuleBasedContentSafety::MASK)
        ->and($fourth->refresh()->body)->toBe('mine is 98470 54321')
        ->and(MessageFlag::query()->count())->toBe(1);
});

it('A13 repetition: the same text into chat.spam_repeat_threads different threads within the window is flagged', function (): void {
    $sender = groom();
    $text = 'Hello dear, I liked your profile very much, please reply soon';
    foreach (['+919800000201', '+919800000202', '+919800000203'] as $i => $phone) {
        [, $bride] = chatPairOf($sender->refresh(), bride($phone), $i === 0 ? PlanCode::Gold : null);
        chatSend($sender->refresh(), $bride, $text);
    }

    $flags = MessageFlag::query()->where('rule', MessageFlagRule::Repetition->value)->get();
    expect($flags)->toHaveCount(1)   // the third thread is the one that trips it
        ->and(Message::query()->whereNotNull('body_hash')->distinct()->count('body_hash'))->toBe(1);

    // A short greeting is never counted as spam.
    expect(safetyScan('good morning', early: false)->bodyHash)->toBeNull();
});

it('images are not scanned; a SYSTEM message is never flagged', function (): void {
    fakeMediaDisks();
    [$groom, $bride] = chatConnected();
    app(App\Actions\Chat\SendMessage::class)->image($groom, $bride->profile, gradientPhoto(), (string) Illuminate\Support\Str::uuid());

    expect(MessageFlag::query()->count())->toBe(0);
});

it('the A04 profile pre-flags still find abusive words through the shared ProfanityMatcher', function (): void {
    $flags = app(App\Domain\Moderation\ModerationFlags::class)->forTexts(['About me' => 'not a bastard']);

    expect(collect($flags)->pluck('code')->all())->toContain('profanity');
});
