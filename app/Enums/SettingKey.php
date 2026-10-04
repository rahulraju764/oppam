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
    case DailyMatchCount = 'matching.daily_match_count';
    case PhotosMaxAdditional = 'photos.max_additional';
    case PhotosMaxUploadMb = 'photos.max_upload_mb';
    case LikesSpamThresholdPerHour = 'likes.spam_threshold_per_hour';
    case SearchMaxPerMinute = 'search.max_per_minute';
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
            self::DailyMatchCount => 10,
            self::PhotosMaxAdditional => 9,
            self::PhotosMaxUploadMb => 8,
            self::LikesSpamThresholdPerHour => 30,
            self::SearchMaxPerMinute => 60,   // M04 / CLAUDE.md rule 10: searches (incl. "load more") per member
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
            self::DailyMatchCount => ['required', 'integer', 'min:1', 'max:50'],
            self::PhotosMaxAdditional => ['required', 'integer', 'min:0', 'max:20'],
            self::PhotosMaxUploadMb => ['required', 'integer', 'min:1', 'max:20'],
            self::LikesSpamThresholdPerHour => ['required', 'integer', 'min:5', 'max:500'],
            self::SearchMaxPerMinute => ['required', 'integer', 'min:10', 'max:600'],
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
