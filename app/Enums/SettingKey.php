<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every admin-editable setting (PRD A15 "Site settings" + "Business rules"), with its type,
 * default and validation — registered in ONE place (laravel-patterns.md "Config & settings").
 * Read with settings(SettingKey::InterestExpiryDays); edit only through the UpdateSetting Action
 * (validated + audited). Plan limits are NOT here — they live in plan_features (A06), including
 * the Free "one reply per conversation" allowance. The brand name is config (oppam.site.name):
 * renaming the brand also changes logos and assets, so it is a deployment change, not a setting.
 */
enum SettingKey: string
{
    // ---- Site (A15 "Site settings") ----
    case SiteSupportEmail = 'site.support_email';
    case SiteInfoEmail = 'site.info_email';
    case SitePhoneDisplay = 'site.phone_display';
    case SitePhoneDial = 'site.phone_dial';
    case SiteAddress = 'site.address';
    case SiteHours = 'site.hours';
    case SiteMaintenanceMessage = 'site.maintenance_message';
    case SeoIndexable = 'seo.indexable';

    // ---- Business rules (A15) ----
    case MinAgeFemale = 'profile.min_age_female';
    case MinAgeMale = 'profile.min_age_male';
    case ProfileAutoHideInactiveDays = 'profile.auto_hide_inactive_days';
    case MembersPurgeAfterDays = 'members.purge_after_days';
    case InterestExpiryDays = 'interest.expiry_days';
    case InterestResendCooldownDays = 'interest.resend_cooldown_days';
    case ChatUnsendWindowMinutes = 'chat.unsend_window_minutes';
    case ChatMaxPerMinute = 'chat.max_per_minute';
    case ChatMaxLength = 'chat.max_length';
    case ChatImageMaxMb = 'chat.image_max_mb';
    case ChatRetentionDays = 'chat.retention_days';
    case ChatDigestDelayMinutes = 'chat.digest_delay_minutes';
    case ChatSafetyFirstMessages = 'chat.safety_first_messages';
    case ChatSpamRepeatThreads = 'chat.spam_repeat_threads';
    case ChatSpamRepeatWindowMinutes = 'chat.spam_repeat_window_minutes';
    case ChatSpamRepeatMinLength = 'chat.spam_repeat_min_length';
    case ChatAutoFreezeReports = 'chat.auto_freeze_reports';
    case ChatAutoFreezeWindowDays = 'chat.auto_freeze_window_days';
    case ChatReportsMaxPerHour = 'chat.reports_max_per_hour';
    case ChatBreakGlassMinutes = 'chat.break_glass_minutes';
    case ChatOneSidedThreshold = 'chat.one_sided_threshold';
    case SignalsInterestSpamMinSent = 'signals.interest_spam_min_sent';
    case SignalsInterestSpamWindowDays = 'signals.interest_spam_window_days';
    case SignalsInterestSpamMaxAcceptPct = 'signals.interest_spam_max_accept_pct';
    case SignalsNewAccountDays = 'signals.new_account_days';
    case SignalsNewAccountThreadsPerDay = 'signals.new_account_threads_per_day';
    case DailyMatchCount = 'matching.daily_match_count';
    case PhotosMaxAdditional = 'photos.max_additional';
    case PhotosMaxUploadMb = 'photos.max_upload_mb';
    case LikesSpamMaxPer10Minutes = 'likes.spam_max_per_10_minutes';
    case LikesSpamBlockMinutes = 'likes.spam_block_minutes';
    case FavoritesMaxPerMinute = 'favorites.max_per_minute';
    case InterestsMaxPerMinute = 'interests.max_per_minute';
    case SearchMaxPerMinute = 'search.max_per_minute';
    case ProfileViewsMaxPerMinute = 'profile_views.max_per_minute';
    case ModerationSlaHours = 'moderation.sla_hours';
    case SystemQueueBacklogAlert = 'system.queue_backlog_alert';
    case BrokerStaffSeatsDefault = 'broker.staff_seats_default';
    case BrokerImportRowsPerFile = 'broker.import_rows_per_file';
    case BrokerImportRowsPerDay = 'broker.import_rows_per_day';
    case BrokerImportRowsPerDayNew = 'broker.import_rows_per_day_new';
    case BrokerImportAutoPauseRejectionPct = 'broker.import_auto_pause_rejection_pct';
    case OtpTtlMinutes = 'otp.ttl_minutes';
    case OtpMaxAttempts = 'otp.max_attempts';
    case OtpMaxSendsPer15Minutes = 'otp.max_sends_per_15_minutes';
    case OtpMaxSendsPerPhonePerDay = 'otp.max_sends_per_phone_per_day';
    case OtpMaxSendsPerIpPerDay = 'otp.max_sends_per_ip_per_day';

    public function type(): SettingType
    {
        return match ($this) {
            self::SeoIndexable => SettingType::Boolean,
            self::SiteSupportEmail, self::SiteInfoEmail, self::SitePhoneDisplay, self::SitePhoneDial,
            self::SiteAddress, self::SiteHours, self::SiteMaintenanceMessage => SettingType::Text,
            default => SettingType::Integer,
        };
    }

    /** The PRD default (A15, M01, M02, M06, M07, §11A B.24). */
    public function default(): int|bool|string
    {
        return match ($this) {
            self::SiteSupportEmail => 'support@oppam.in',
            self::SiteInfoEmail => 'info@oppam.in',
            self::SitePhoneDisplay => '0895 - 3881 - 2873',
            self::SitePhoneDial => '089538812873',
            self::SiteAddress => "1st floor 272-3, near St George Basilica church,\nAngamaly, Kerala 683572",
            self::SiteHours => 'Monday – Saturday, 9:30am – 6:30pm',
            self::SiteMaintenanceMessage => '',
            self::SeoIndexable => (bool) config('oppam.indexable'),
            self::MinAgeFemale => 18,
            self::MinAgeMale => 21,
            self::ProfileAutoHideInactiveDays => 180,
            self::MembersPurgeAfterDays => 30,   // A03: 30-day restore window, then anonymise
            self::InterestExpiryDays => 30,
            self::InterestResendCooldownDays => 90,
            self::ChatUnsendWindowMinutes => 60,
            self::ChatMaxPerMinute => 30,           // M07 send flow: messages per member per minute
            self::ChatMaxLength => 2000,            // R-M07-4: characters per message
            self::ChatImageMaxMb => 5,              // M07: image messages (paid plans)
            self::ChatRetentionDays => 730,         // R-M07-6: a thread is deleted this long after its last activity
            self::ChatDigestDelayMinutes => 10,     // M07 / M08: offline this long with unread messages → one email digest
            self::ChatSafetyFirstMessages => 10,    // R-M07-4 / A13: contact details and links are masked in a thread's first messages
            self::ChatSpamRepeatThreads => 3,       // A13: the same text into this many different threads …
            self::ChatSpamRepeatWindowMinutes => 60, // … within this window = a repeated-message (spam) flag
            self::ChatSpamRepeatMinLength => 20,    // shorter texts ("hi", "good morning") are never counted as repeats
            self::ChatAutoFreezeReports => 3,       // M09: reported from chat by this many different members …
            self::ChatAutoFreezeWindowDays => 30,   // … within this window → the sender's threads freeze pending review (decisions 2026-10-16)
            self::ChatReportsMaxPerHour => 5,       // M09 / rule 10: chat reports per member per hour
            self::ChatBreakGlassMinutes => 30,      // A13: a break-glass read of a conversation ends after this
            self::ChatOneSidedThreshold => 20,      // A13: this many messages in a row without a reply = harassment signal
            self::SignalsInterestSpamMinSent => 30, // A13 interest spam: at least this many interests sent …
            self::SignalsInterestSpamWindowDays => 7, // … within this window …
            self::SignalsInterestSpamMaxAcceptPct => 5, // … and at most this share accepted
            self::SignalsNewAccountDays => 7,       // A13: an account this young …
            self::SignalsNewAccountThreadsPerDay => 15, // … writing in this many conversations in 24 h = signal
            self::DailyMatchCount => 10,
            self::PhotosMaxAdditional => 9,
            self::PhotosMaxUploadMb => 8,
            self::LikesSpamMaxPer10Minutes => 50,   // R-M06-2: more than this in 10 minutes = like spam
            self::LikesSpamBlockMinutes => 60,      // R-M06-2: the soft block that follows
            self::FavoritesMaxPerMinute => 60,      // A13 "mass favourites via automation": add / remove per member
            self::InterestsMaxPerMinute => 20,      // interests and photo / horoscope requests sent per member (the monthly quota still applies)
            self::SearchMaxPerMinute => 60,   // M04 / CLAUDE.md rule 10: searches (incl. "load more") per member
            self::ProfileViewsMaxPerMinute => 30,   // M03 / M15: recorded profile views per member (more still open, uncounted)
            self::ModerationSlaHours => 24,          // A02 alert strip: a moderation item waiting longer than this breaches the SLA
            self::SystemQueueBacklogAlert => 500,    // A02 alert strip: jobs waiting on one queue before it is called a backlog
            self::BrokerStaffSeatsDefault => 5,
            self::BrokerImportRowsPerFile => 500,
            self::BrokerImportRowsPerDay => 1000,
            self::BrokerImportRowsPerDayNew => 100,
            self::BrokerImportAutoPauseRejectionPct => 40,
            self::OtpTtlMinutes => 5,
            self::OtpMaxAttempts => 3,
            self::OtpMaxSendsPer15Minutes => 3,
            self::OtpMaxSendsPerPhonePerDay => 10,
            // PRD §8.2 says 10/IP/day; Indian carriers put many users behind one IP (CGNAT), so the
            // per-phone limits do the real work and this is an abuse backstop (docs/decisions.md 2026-09-29).
            self::OtpMaxSendsPerIpPerDay => 200,
        };
    }

    /** @return list<string> Laravel validation rules for an edit (A15 "editable, validated, audited"). */
    public function rules(): array
    {
        return match ($this) {
            self::SiteSupportEmail, self::SiteInfoEmail => ['required', 'email', 'max:255'],
            self::SitePhoneDisplay => ['required', 'string', 'max:40'],
            self::SitePhoneDial => ['required', 'string', 'regex:/^\+?[0-9]{6,15}$/'],
            self::SiteAddress => ['required', 'string', 'max:300'],
            self::SiteHours => ['required', 'string', 'max:120'],
            self::SiteMaintenanceMessage => ['nullable', 'string', 'max:300'],
            self::SeoIndexable => ['required', 'boolean'],
            // Legal minimum marriage ages in India: never below 18 / 21.
            self::MinAgeFemale => ['required', 'integer', 'min:18', 'max:40'],
            self::MinAgeMale => ['required', 'integer', 'min:21', 'max:40'],
            self::ProfileAutoHideInactiveDays => ['required', 'integer', 'min:30', 'max:730'],
            self::MembersPurgeAfterDays => ['required', 'integer', 'min:30', 'max:365'],
            self::InterestExpiryDays => ['required', 'integer', 'min:1', 'max:365'],
            self::InterestResendCooldownDays => ['required', 'integer', 'min:0', 'max:365'],
            self::ChatUnsendWindowMinutes => ['required', 'integer', 'min:0', 'max:1440'],
            self::ChatMaxPerMinute => ['required', 'integer', 'min:5', 'max:300'],
            self::ChatMaxLength => ['required', 'integer', 'min:100', 'max:5000'],
            self::ChatImageMaxMb => ['required', 'integer', 'min:1', 'max:10'],
            self::ChatRetentionDays => ['required', 'integer', 'min:180', 'max:3650'],
            self::ChatDigestDelayMinutes => ['required', 'integer', 'min:5', 'max:240'],
            self::ChatSafetyFirstMessages => ['required', 'integer', 'min:0', 'max:100'],
            self::ChatSpamRepeatThreads => ['required', 'integer', 'min:2', 'max:50'],
            self::ChatSpamRepeatWindowMinutes => ['required', 'integer', 'min:5', 'max:1440'],
            self::ChatSpamRepeatMinLength => ['required', 'integer', 'min:5', 'max:500'],
            self::ChatAutoFreezeReports => ['required', 'integer', 'min:2', 'max:20'],
            self::ChatAutoFreezeWindowDays => ['required', 'integer', 'min:1', 'max:365'],
            self::ChatReportsMaxPerHour => ['required', 'integer', 'min:1', 'max:50'],
            self::ChatBreakGlassMinutes => ['required', 'integer', 'min:5', 'max:120'],
            self::ChatOneSidedThreshold => ['required', 'integer', 'min:5', 'max:200'],
            self::SignalsInterestSpamMinSent => ['required', 'integer', 'min:5', 'max:500'],
            self::SignalsInterestSpamWindowDays => ['required', 'integer', 'min:1', 'max:30'],
            self::SignalsInterestSpamMaxAcceptPct => ['required', 'integer', 'min:0', 'max:50'],
            self::SignalsNewAccountDays => ['required', 'integer', 'min:1', 'max:90'],
            self::SignalsNewAccountThreadsPerDay => ['required', 'integer', 'min:3', 'max:200'],
            self::DailyMatchCount => ['required', 'integer', 'min:1', 'max:50'],
            self::PhotosMaxAdditional => ['required', 'integer', 'min:0', 'max:20'],
            self::PhotosMaxUploadMb => ['required', 'integer', 'min:1', 'max:20'],
            self::LikesSpamMaxPer10Minutes => ['required', 'integer', 'min:5', 'max:500'],
            self::LikesSpamBlockMinutes => ['required', 'integer', 'min:1', 'max:1440'],
            self::FavoritesMaxPerMinute => ['required', 'integer', 'min:5', 'max:600'],
            self::InterestsMaxPerMinute => ['required', 'integer', 'min:1', 'max:300'],
            self::SearchMaxPerMinute => ['required', 'integer', 'min:10', 'max:600'],
            self::ProfileViewsMaxPerMinute => ['required', 'integer', 'min:5', 'max:300'],
            self::ModerationSlaHours => ['required', 'integer', 'min:1', 'max:720'],
            self::SystemQueueBacklogAlert => ['required', 'integer', 'min:10', 'max:100000'],
            self::BrokerStaffSeatsDefault => ['required', 'integer', 'min:1', 'max:100'],
            self::BrokerImportRowsPerFile => ['required', 'integer', 'min:1', 'max:5000'],
            self::BrokerImportRowsPerDay, self::BrokerImportRowsPerDayNew => ['required', 'integer', 'min:1', 'max:20000'],
            self::BrokerImportAutoPauseRejectionPct => ['required', 'integer', 'min:1', 'max:100'],
            self::OtpTtlMinutes => ['required', 'integer', 'min:1', 'max:30'],
            self::OtpMaxAttempts, self::OtpMaxSendsPer15Minutes => ['required', 'integer', 'min:1', 'max:10'],
            self::OtpMaxSendsPerPhonePerDay => ['required', 'integer', 'min:1', 'max:50'],
            self::OtpMaxSendsPerIpPerDay => ['required', 'integer', 'min:10', 'max:5000'],
        };
    }

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }
}
