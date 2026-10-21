<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Contracts\ContentSafety;
use App\Data\Chat\SafetyFindingData;
use App\Data\Chat\SafetyHitData;
use App\Data\Chat\SafetyRuleData;
use App\Data\Chat\SafetyScanData;
use App\Domain\Chat\ChatSafetyRules;
use App\Domain\Moderation\ProfanityMatcher;
use App\Enums\MessageFlagRule;
use App\Enums\SafetyAction;
use App\Enums\SafetyRule;
use App\Enums\SettingKey;
use App\Models\Message;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The chat rules (PRD M07 send flow, R-M07-4, A13), applied in SafetyRule order with each rule's
 * action for the message's position — "early" = among the thread's first `chat.safety_first_messages`
 * messages (both members together):
 *  - MASK: every match replaced by the mask in the stored / shown text, and flagged;
 *  - FLAG: the text is kept, the message goes to the A13 queue;
 *  - BLOCK: the scan names the rule — SendMessage refuses the message (nothing stored);
 *  - OFF: ignored.
 * The defaults (SafetyRule) are the P4.3a behaviour; the A13 rules editor changes patterns and
 * actions (ChatSafetyRules). Links and UPI ids are looked for with email addresses taken out.
 * PROFANITY uses the A11 word lists; REPETITION = the same text (at least
 * `chat.spam_repeat_min_length` characters) sent by the member into `chat.spam_repeat_threads`
 * different threads within `chat.spam_repeat_window_minutes`.
 * A pattern that fails at run time (PCRE error) never empties or drops a message: the text stays as
 * typed, the message is flagged for that rule so a person looks at it, and the error is logged
 * (rule key and PCRE error only — never the text).
 */
final class RuleBasedContentSafety implements ContentSafety
{
    public const MASK = '••••••';

    public function __construct(
        private readonly ChatSafetyRules $rules,
        private readonly ProfanityMatcher $profanity,
    ) {}

    public function scan(string $body, string $senderProfileId, string $conversationId, bool $early): SafetyScanData
    {
        $rules = $this->rules->all();
        [$text, $findings, $hits, $blockedBy] = $this->apply($body, $early, $rules);

        $hash = $this->hash($body);
        $repetition = $rules[SafetyRule::Repetition->value]->action($early);
        if ($repetition !== SafetyAction::Off && $hash !== null && $this->repeated($hash, $senderProfileId, $conversationId)) {
            $hits[] = new SafetyHitData(SafetyRule::Repetition, $repetition, []);
            if ($repetition === SafetyAction::Block) {
                $blockedBy ??= SafetyRule::Repetition;
            } else {
                $this->add($findings, MessageFlagRule::Repetition, []);
            }
        }

        return new SafetyScanData($text, array_values($findings), $hash, $text !== $body, $blockedBy, $hits);
    }

    public function dryRun(string $body, bool $early, ?SafetyRuleData $draft = null): SafetyScanData
    {
        $rules = $this->rules->all();
        if ($draft !== null) {
            $rules[$draft->rule->value] = $draft;
        }

        [$text, $findings, $hits, $blockedBy] = $this->apply($body, $early, $rules);

        return new SafetyScanData($text, array_values($findings), null, $text !== $body, $blockedBy, $hits);
    }

    /**
     * Every rule but REPETITION (which needs the database).
     *
     * @param  array<string, SafetyRuleData>  $rules
     * @return array{0: string, 1: array<string, SafetyFindingData>, 2: list<SafetyHitData>, 3: SafetyRule|null}
     */
    private function apply(string $body, bool $early, array $rules): array
    {
        $text = $body;
        $findings = [];
        $hits = [];
        $blockedBy = null;
        $emails = $rules[SafetyRule::Email->value]->regex();
        $withoutEmails = $emails === null ? $body : ($this->replace($emails, $body, ' ', SafetyRule::Email) ?? $body);

        foreach ($rules as $rule) {
            $action = $rule->action($early);
            if ($action === SafetyAction::Off || $rule->rule === SafetyRule::Repetition) {
                continue;
            }

            if ($rule->rule === SafetyRule::Profanity) {
                if ($action === SafetyAction::Mask) {
                    [$text, $found] = $this->profanity->mask($text, self::MASK);
                } else {
                    $word = $this->profanity->firstMatch($body);
                    $found = $word === null ? [] : [$word];
                }
            } elseif ($action === SafetyAction::Mask) {
                // Links / UPI ids leave email addresses alone ("gmail.com" is part of one, not a link).
                $masked = $rule->rule->ignoresEmails() && $emails !== null
                    ? $this->maskAroundEmails((string) $rule->regex(), $emails, $text, $rule->rule)
                    : $this->mask((string) $rule->regex(), $text, $rule->rule);
                $found = $masked === null ? null : $masked[1];
                $text = $masked === null ? $text : $masked[0];
            } else {
                $found = $this->matches((string) $rule->regex(), $rule->rule->ignoresEmails() ? $withoutEmails : $body, $rule->rule);
            }

            if ($found === null) {
                // The pattern failed: keep the text, let a person look at the message.
                $this->add($findings, $rule->rule->flagRule(), []);

                continue;
            }
            if ($found === []) {
                continue;
            }

            $hits[] = new SafetyHitData($rule->rule, $action, $found);
            if ($action === SafetyAction::Block) {
                $blockedBy ??= $rule->rule;
            } else {
                $this->add($findings, $rule->rule->flagRule(), $found);
            }
        }

        return [$text, $findings, $hits, $blockedBy];
    }

    /**
     * Replace every match with the mask.
     *
     * @return array{0: string, 1: list<string>}|null the masked text and what was masked; null on a PCRE error
     */
    private function mask(string $regex, string $text, SafetyRule $rule): ?array
    {
        $found = [];
        $masked = @preg_replace_callback($regex, function (array $match) use (&$found): string {
            $found[] = trim($match[0]);

            return self::MASK;
        }, $text);

        if ($masked === null) {
            $this->failed($rule);

            return null;
        }

        return [$masked, $found];
    }

    /**
     * mask(), with the email addresses still in the text set aside first and put back after.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private function maskAroundEmails(string $regex, string $emailRegex, string $text, SafetyRule $rule): ?array
    {
        $kept = [];
        $protected = @preg_replace_callback($emailRegex, function (array $match) use (&$kept): string {
            $kept[] = $match[0];

            return "\u{E000}".(count($kept) - 1)."\u{E001}";
        }, $text);
        if ($protected === null) {
            $this->failed(SafetyRule::Email);

            return null;
        }

        $masked = $this->mask($regex, $protected, $rule);
        if ($masked === null) {
            return null;
        }

        $restored = (string) preg_replace_callback("/\u{E000}(\\d+)\u{E001}/u", fn (array $m): string => $kept[(int) $m[1]] ?? '', $masked[0]);

        return [$restored, $masked[1]];
    }

    /** @return list<string>|null null on a PCRE error */
    private function matches(string $regex, string $text, SafetyRule $rule): ?array
    {
        if (@preg_match_all($regex, $text, $all) === false) {
            $this->failed($rule);

            return null;
        }

        return array_map('trim', $all[0]);
    }

    private function replace(string $regex, string $text, string $with, SafetyRule $rule): ?string
    {
        $replaced = @preg_replace($regex, $with, $text);
        if ($replaced === null) {
            $this->failed($rule);
        }

        return $replaced;
    }

    private function failed(SafetyRule $rule): void
    {
        Log::warning('Chat safety rule failed to run', ['rule' => $rule->value, 'pcre_error' => preg_last_error_msg()]);
    }

    /**
     * One finding per A13 reason; excerpts joined (bounded).
     *
     * @param  array<string, SafetyFindingData>  $findings
     * @param  list<string>  $excerpts
     */
    private function add(array &$findings, MessageFlagRule $rule, array $excerpts): void
    {
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
