<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The chat safety rules (PRD M07 send flow, R-M07-4, A13 "Wordlist & rules editor"), in the order
 * ContentSafety applies them — emails before UPI ids and handles (all contain "@"), links before UPI
 * ids. Each has a default pattern and a default action for a thread's first
 * `chat.safety_first_messages` messages ("early") and for later ones; the A13 rules editor stores
 * overrides (ChatSafetyRules), and "reset" goes back to these. The defaults are the P4.3a behaviour:
 * contact details hidden early and allowed later; links and UPI ids hidden early and flagged later;
 * money requests, abusive words and repeated messages always flagged.
 *
 * Patterns are the regex BODY (delimiters "/…/iu" are added): case-insensitive and Unicode-aware.
 * PROFANITY uses the A11 word lists, REPETITION the A15 `chat.spam_repeat_*` settings — neither has a
 * pattern here.
 */
enum SafetyRule: string
{
    case Email = 'email';
    case Phone = 'phone';
    case Handle = 'handle';
    case Link = 'link';
    case Upi = 'upi';
    case Money = 'money';
    case Profanity = 'profanity';
    case Repetition = 'repetition';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email addresses'),
            self::Phone => __('Phone numbers'),
            self::Handle => __('Social media handles (@name)'),
            self::Link => __('Web addresses'),
            self::Upi => __('UPI ids (name@bank)'),
            self::Money => __('Money and bank requests'),
            self::Profanity => __('Abusive words (A11 lists)'),
            self::Repetition => __('The same message sent to many members'),
        };
    }

    /** The A13 queue reason a match is filed under. */
    public function flagRule(): MessageFlagRule
    {
        return match ($this) {
            self::Email, self::Phone, self::Handle => MessageFlagRule::ContactInfo,
            self::Link => MessageFlagRule::ExternalLink,
            self::Upi, self::Money => MessageFlagRule::MoneyRequest,
            self::Profanity => MessageFlagRule::Profanity,
            self::Repetition => MessageFlagRule::Repetition,
        };
    }

    /** Whether an admin edits this rule's pattern (PROFANITY / REPETITION have none). */
    public function hasPattern(): bool
    {
        return $this->defaultPattern() !== null;
    }

    public function defaultPattern(): ?string
    {
        return match ($this) {
            self::Email => '[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}',
            // 10 or more digits, with spaces / dots / dashes / brackets between them.
            self::Phone => '(?:\+?\d[\s.\-()]*){9,}\d',
            // An @ that starts a word (Instagram, Telegram …).
            self::Handle => '(?<![\w.@])@[A-Z0-9_.]{3,30}',
            self::Link => '\b(?:https?:\/\/|www\.)\S+|\b[A-Z0-9\-]{2,}\.(?:com|in|net|org|me|ly|io|co|app|link|xyz|info|site|online)\b(?:\/\S*)?',
            // name@bank (no dot after the @): a UPI id, not an email address.
            self::Upi => '(?<![\w.@])[A-Z0-9._\-]{2,64}@[A-Z]{2,32}(?![\w.@])',
            self::Money => '₹\s?\d|\b(?:rs\.?|inr)\s?\d|upi:\/\/|\b[A-Z]{4}0[A-Z0-9]{6}\b|\b(?:gift\s?cards?|western\s?union|bitcoin|crypto|paytm|g\s?pay|google\s?pay|phone\s?pe|bank\s+account|account\s+number|a\/c\s*(?:no|number))\b|\b(?:send|transfer|lend|borrow|need|give)\b[^.!?\n]{0,30}\b(?:money|cash|rupees|amount|loan|lakhs?)\b',
            self::Profanity, self::Repetition => null,
        };
    }

    public function defaultAction(bool $early): SafetyAction
    {
        return match ($this) {
            self::Email, self::Phone, self::Handle => $early ? SafetyAction::Mask : SafetyAction::Off,
            self::Link, self::Upi => $early ? SafetyAction::Mask : SafetyAction::Flag,
            self::Money, self::Profanity, self::Repetition => SafetyAction::Flag,
        };
    }

    /** @return list<SafetyAction> REPETITION can't hide anything — the whole message is the problem. */
    public function allowedActions(): array
    {
        return $this === self::Repetition
            ? [SafetyAction::Flag, SafetyAction::Block, SafetyAction::Off]
            : SafetyAction::cases();
    }

    /** Links and UPI ids are looked for with email addresses taken out ("gmail.com" is no link). */
    public function ignoresEmails(): bool
    {
        return $this === self::Link || $this === self::Upi;
    }

    /** Why a BLOCK rule refused a message — told to the sender, never the pattern itself. */
    public function refusal(): string
    {
        return match ($this) {
            self::Email, self::Phone, self::Handle => __('it contains contact details'),
            self::Link => __('it contains a web address'),
            self::Upi, self::Money => __('it asks for money or payment details'),
            self::Profanity => __('it contains abusive language'),
            self::Repetition => __('the same message was just sent to many members'),
        };
    }
}
