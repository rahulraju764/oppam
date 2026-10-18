<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\ContentSafety;
use App\Data\Chat\SafetyFindingData;
use App\Data\Chat\SafetyScanData;
use App\Domain\Moderation\ProfanityMatcher;
use App\Enums\MessageFlagRule;
use App\Enums\SettingKey;
use App\Models\Message;
use App\Support\Facades\Settings;
use Illuminate\Support\Str;

/**
 * The built-in chat rules (PRD M07 send flow, R-M07-4, A13). In a thread's first
 * `chat.safety_first_messages` messages (both members together):
 *  - phone numbers, email addresses and @handles → masked + CONTACT_INFO;
 *  - web addresses → masked + LINK;
 *  - UPI ids (name@bank) → masked + MONEY.
 * Later in the thread the members may share contacts; links and UPI ids are still flagged (not
 * masked). Always: money / bank requests (₹ amounts, "send money", IFSC, payment apps) → MONEY;
 * a word from the A11 abuse lists → PROFANITY; the same text (at least
 * `chat.spam_repeat_min_length` characters) sent by the member into `chat.spam_repeat_threads`
 * different threads within `chat.spam_repeat_window_minutes` → REPETITION.
 * The patterns become editable in the A13 rules editor (P4.3b).
 */
final class RuleBasedContentSafety implements ContentSafety
{
    public const MASK = '••••••';

    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu';

    /** name@bank (no dot after the @): a UPI id, not an email address. */
    private const UPI = '/(?<![\w.@])[A-Z0-9._\-]{2,64}@[A-Z]{2,32}(?![\w.@])/iu';

    /** 10 or more digits, with spaces / dots / dashes / brackets between them. */
    private const PHONE = '/(?:\+?\d[\s.\-()]*){9,}\d/u';

    /** An @handle (Instagram, Telegram …): an @ that starts a word. */
    private const HANDLE = '/(?<![\w.@])@[A-Z0-9_.]{3,30}/iu';

    private const URL = '/\b(?:https?:\/\/|www\.)\S+|\b[A-Z0-9\-]{2,}\.(?:com|in|net|org|me|ly|io|co|app|link|xyz|info|site|online)\b(?:\/\S*)?/iu';

    private const MONEY = '/₹\s?\d|\b(?:rs\.?|inr)\s?\d|upi:\/\/|\b[A-Z]{4}0[A-Z0-9]{6}\b|\b(?:gift\s?cards?|western\s?union|bitcoin|crypto|paytm|g\s?pay|google\s?pay|phone\s?pe|bank\s+account|account\s+number|a\/c\s*(?:no|number))\b|\b(?:send|transfer|lend|borrow|need|give)\b[^.!?\n]{0,30}\b(?:money|cash|rupees|amount|loan|lakhs?)\b/iu';

    public function __construct(private readonly ProfanityMatcher $profanity) {}

    public function scan(string $body, string $senderProfileId, string $conversationId, bool $early): SafetyScanData
    {
        $findings = [];
        $text = $body;

        if ($early) {
            // Order matters: emails before UPI ids and handles (all contain "@").
            [$text, $contacts] = $this->mask($text, [self::EMAIL, self::PHONE, self::HANDLE]);
            [$text, $links] = $this->mask($text, [self::URL]);
            [$text, $upi] = $this->mask($text, [self::UPI]);
            $this->add($findings, MessageFlagRule::ContactInfo, $contacts);
            $this->add($findings, MessageFlagRule::ExternalLink, $links);
            $this->add($findings, MessageFlagRule::MoneyRequest, $upi);
        } else {
            // An email address is neither a link ("gmail.com") nor a UPI id.
            $withoutEmails = (string) preg_replace(self::EMAIL, ' ', $body);
            $this->add($findings, MessageFlagRule::ExternalLink, $this->matches($withoutEmails, self::URL));
            $this->add($findings, MessageFlagRule::MoneyRequest, $this->matches($withoutEmails, self::UPI));
        }

        $this->add($findings, MessageFlagRule::MoneyRequest, $this->matches($body, self::MONEY));

        $word = $this->profanity->firstMatch($body);
        if ($word !== null) {
            $this->add($findings, MessageFlagRule::Profanity, [$word]);
        }

        $hash = $this->hash($body);
        if ($hash !== null && $this->repeated($hash, $senderProfileId, $conversationId)) {
            $this->add($findings, MessageFlagRule::Repetition, []);
        }

        return new SafetyScanData($text, array_values($findings), $hash, $text !== $body);
    }

    /**
     * Replace every match with the mask.
     *
     * @param  list<string>  $patterns
     * @return array{0: string, 1: list<string>} the masked text and what was masked
     */
    private function mask(string $text, array $patterns): array
    {
        $found = [];

        foreach ($patterns as $pattern) {
            $text = (string) preg_replace_callback($pattern, function (array $match) use (&$found): string {
                $found[] = trim($match[0]);

                return self::MASK;
            }, $text);
        }

        return [$text, $found];
    }

    /** @return list<string> */
    private function matches(string $text, string $pattern): array
    {
        preg_match_all($pattern, $text, $all);

        return array_map('trim', $all[0]);
    }

    /**
     * One finding per rule; excerpts joined (bounded).
     *
     * @param  array<string, SafetyFindingData>  $findings
     * @param  list<string>  $excerpts
     */
    private function add(array &$findings, MessageFlagRule $rule, array $excerpts): void
    {
        if ($excerpts === [] && $rule !== MessageFlagRule::Repetition) {
            return;
        }

        $previous = $findings[$rule->value]->excerpt ?? null;
        $joined = implode(', ', array_filter([$previous, ...array_unique($excerpts)]));
        $findings[$rule->value] = new SafetyFindingData($rule, $joined === '' ? null : Str::limit($joined, 300));
    }

    /** SHA-256 of the lower-cased, squished text — or null when it is too short to count as spam. */
    private function hash(string $body): ?string
    {
        $normal = mb_strtolower(Str::squish($body));

        return mb_strlen($normal) >= Settings::int(SettingKey::ChatSpamRepeatMinLength) ? hash('sha256', $normal) : null;
    }

    private function repeated(string $hash, string $senderProfileId, string $conversationId): bool
    {
        $others = Message::query()
            ->where('sender_profile_id', $senderProfileId)
            ->where('body_hash', $hash)
            ->where('created_at', '>=', now()->subMinutes(Settings::int(SettingKey::ChatSpamRepeatWindowMinutes)))
            ->where('conversation_id', '!=', $conversationId)
            ->distinct()->count('conversation_id');

        // This message is the next one: N threads in all, counting this thread.
        return $others + 1 >= Settings::int(SettingKey::ChatSpamRepeatThreads);
    }
}
