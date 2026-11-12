<?php

declare(strict_types=1);

namespace App\Enums;

use App\Data\Content\SeoData;

/**
 * Every admin-editable setting (PRD A15 "Site settings" + "Business rules"), with its type,
 * default and validation — registered in ONE place (laravel-patterns.md "Config & settings").
 * Read with settings(SettingKey::InterestExpiryDays); edit only through the UpdateSetting Action
 * (validated + audited). Plan limits are NOT here — they live in plan_features (A06), including
 * the Free "one reply per conversation" allowance. Since P8.4 (owner 2026-11-12) the site name,
 * logo, social links and SEO defaults are settings too (config / env are their defaults); the
 * A15 screens are generated from label() / section() / unit() — no list of keys in a component.
 */
enum SettingKey: string
{
    // ---- Site (A15 "Site settings") ----
    case SiteName = 'site.name';
    /** Public-disk path of an uploaded logo (UploadSiteLogo); empty = the bundled logo. */
    case SiteLogoPath = 'site.logo_path';
    case SocialFacebook = 'social.facebook';
    case SocialTwitter = 'social.twitter';
    case SocialInstagram = 'social.instagram';
    case SocialLinkedin = 'social.linkedin';
    case SocialYoutube = 'social.youtube';
    case SeoDefaultTitle = 'seo.default_title';
    case SeoDefaultDescription = 'seo.default_description';
    case SiteSupportEmail = 'site.support_email';
    case SiteInfoEmail = 'site.info_email';
    case SitePhoneDisplay = 'site.phone_display';
    case SitePhoneDial = 'site.phone_dial';
    case SiteAddress = 'site.address';
    case SiteHours = 'site.hours';
    case SiteMaintenanceMessage = 'site.maintenance_message';
    case SeoIndexable = 'seo.indexable';
    case ContentHomeMembersMin = 'content.home_members_min';
    case ContentHomeToggleMaxPerHour = 'content.home_toggle_max_per_hour';

    // ---- Business rules (A15) ----
    case MinAgeFemale = 'profile.min_age_female';
    case MinAgeMale = 'profile.min_age_male';
    case ProfileAutoHideInactiveDays = 'profile.auto_hide_inactive_days';
    case MembersPurgeAfterDays = 'members.purge_after_days';
    case MembersSettingsChangesPerHour = 'members.settings_changes_per_hour';
    case MembersEmailChangesPerDay = 'members.email_changes_per_day';
    case NotificationsQuietFrom = 'notifications.quiet_from';
    case NotificationsQuietUntil = 'notifications.quiet_until';
    case CampaignsApprovalThreshold = 'campaigns.approval_threshold';
    case CampaignsMaxPerMinute = 'campaigns.max_per_minute';
    case JourneysLedgerRetentionDays = 'journeys.ledger_retention_days';
    case SettingsIntegrationChecksPerHour = 'settings.integration_checks_per_hour';
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
    case BrokerImportAutoPauseMinDecided = 'broker.import_auto_pause_min_decided';
    case BrokerImportFileRetentionDays = 'broker.import_file_retention_days';
    case BrokerPayoutMinimumPaise = 'broker.payout_minimum_paise';
    case BrokerPayoutSecondApproverPaise = 'broker.payout_second_approver_paise';
    case BrokerPayoutTdsBps = 'broker.payout_tds_bps';
    case BrokerPayoutReminderDay = 'broker.payout_reminder_day';
    case BrokerDisputeUpheldLimit = 'broker.dispute_upheld_limit';
    case BrokerDisputeWindowDays = 'broker.dispute_window_days';
    case BrokerExportAnomalyPerHour = 'broker.export_anomaly_per_hour';
    case BrokerExportRepeatWindowMinutes = 'broker.export_repeat_window_minutes';
    case BrokerSignalSharedSignups = 'broker.signal_shared_signups';
    case BrokerSignalSpikeMultiplier = 'broker.signal_spike_multiplier';
    case BrokerSignalSpikeMinimum = 'broker.signal_spike_minimum';
    case BrokerSignalIncompletePct = 'broker.signal_incomplete_pct';
    case BrokerSignalRefundCount = 'broker.signal_refund_count';
    case BrokerSignalRejectionPct = 'broker.signal_rejection_pct';
    case BrokerSignalStaffDevices = 'broker.signal_staff_devices';
    case BrokerSignalImportSharedPrefix = 'broker.signal_import_shared_prefix';
    case OtpTtlMinutes = 'otp.ttl_minutes';
    case OtpMaxAttempts = 'otp.max_attempts';
    case OtpMaxSendsPer15Minutes = 'otp.max_sends_per_15_minutes';
    case OtpMaxSendsPerPhonePerDay = 'otp.max_sends_per_phone_per_day';
    case OtpMaxSendsPerIpPerDay = 'otp.max_sends_per_ip_per_day';

    // ---- Billing (M10, A06, A15) ----
    case BillingGstRateBps = 'billing.gst_rate_bps';
    case BillingOrderTtlMinutes = 'billing.order_ttl_minutes';
    case BillingCheckoutsMaxPerHour = 'billing.checkouts_max_per_hour';
    case BillingCouponAttemptsPerHour = 'billing.coupon_attempts_per_hour';
    case BillingRefundNotSentWaitMinutes = 'billing.refund_not_sent_wait_minutes';
    case BillingSellerName = 'billing.seller_name';
    case BillingSellerGstin = 'billing.seller_gstin';
    case BillingSellerAddress = 'billing.seller_address';
    case BillingSellerStateCode = 'billing.seller_state_code';
    case BillingSacCode = 'billing.sac_code';

    // ---- ID verification (M09, A05, P6.1) ----
    case VerificationAgingWarnHours = 'verification.aging_warn_hours';
    case VerificationAgingBreachHours = 'verification.aging_breach_hours';
    case VerificationPurgeAfterDays = 'verification.purge_after_days';
    case VerificationMaxSubmissionsPerDay = 'verification.max_submissions_per_day';
    case VerificationRapidResubmitCount = 'verification.rapid_resubmit_count';
    case VerificationRapidResubmitDays = 'verification.rapid_resubmit_days';

    // ---- Safety & support (P6.2, M09 / A10, decisions 2026-10-23) ----
    case SafetyP0SlaHours = 'safety.p0_sla_hours';
    case SafetyP1SlaHours = 'safety.p1_sla_hours';
    case SafetyP2SlaHours = 'safety.p2_sla_hours';
    case SafetyEscalateReporters = 'safety.escalate_reporters';
    case SafetyEscalateWindowDays = 'safety.escalate_window_days';
    case SafetyReportsMaxPerHour = 'safety.reports_max_per_hour';
    case SafetyBlocksMaxPerHour = 'safety.blocks_max_per_hour';
    case SafetyEvidenceContextMessages = 'safety.evidence_context_messages';
    case SafetyPhashBanDistance = 'safety.phash_ban_distance';
    case SafetyOncallPhones = 'safety.oncall_phones';
    case SupportSlaSafetyHours = 'support.sla_safety_hours';
    case SupportSlaPaymentsHours = 'support.sla_payments_hours';
    case SupportSlaDefaultHours = 'support.sla_default_hours';
    case SupportTicketsMaxPerHour = 'support.tickets_max_per_hour';
    case ContactMaxPerHour = 'contact.max_per_hour';
    case ContactSpamRetentionDays = 'contact.spam_retention_days';
    case ContactRetentionDays = 'contact.retention_days';

    public function type(): SettingType
    {
        return match ($this) {
            self::SeoIndexable => SettingType::Boolean,
            self::SiteName, self::SiteLogoPath, self::SocialFacebook, self::SocialTwitter, self::SocialInstagram,
            self::SocialLinkedin, self::SocialYoutube, self::SeoDefaultTitle, self::SeoDefaultDescription,
            self::SiteSupportEmail, self::SiteInfoEmail, self::SitePhoneDisplay, self::SitePhoneDial,
            self::SiteAddress, self::SiteHours, self::SiteMaintenanceMessage,
            self::BillingSellerName, self::BillingSellerGstin, self::BillingSellerAddress,
            self::BillingSellerStateCode, self::BillingSacCode, self::SafetyOncallPhones => SettingType::Text,
            default => SettingType::Integer,
        };
    }

    /** The PRD default (A15, M01, M02, M06, M07, §11A B.24). */
    public function default(): int|bool|string
    {
        return match ($this) {
            // Until an admin sets them (P8.4, owner 2026-11-12): the deployment's config / env values.
            self::SiteName => (string) config('oppam.site.name'),
            self::SiteLogoPath => '',
            self::SocialFacebook => (string) config('oppam.site.social.facebook'),
            self::SocialTwitter => (string) config('oppam.site.social.twitter'),
            self::SocialInstagram => (string) config('oppam.site.social.instagram'),
            self::SocialLinkedin => (string) config('oppam.site.social.linkedin'),
            self::SocialYoutube => (string) config('oppam.site.social.youtube'),
            self::SeoDefaultTitle => SeoData::DEFAULT_TITLE,
            self::SeoDefaultDescription => SeoData::DEFAULT_DESCRIPTION,
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
            self::MembersSettingsChangesPerHour => 30,   // M14: privacy / notification saves per member (rule 10)
            self::MembersEmailChangesPerDay => 5,        // M14 Account: email confirmation links a member may ask for per day
            self::NotificationsQuietFrom => 22,          // M08: no SMS from this IST hour … (one window for everyone, owner 2026-11-06 / 11-07)
            self::NotificationsQuietUntil => 7,          // … until this IST hour
            self::CampaignsApprovalThreshold => 10_000,  // A14: a campaign to more members than this needs a second admin
            self::CampaignsMaxPerMinute => 1_000,        // A14: the highest throttle a campaign may set (messages per minute)
            self::JourneysLedgerRetentionDays => 400,    // A14 journeys (P8.3b2): the "already sent" ledger is kept this long
            self::SettingsIntegrationChecksPerHour => 10, // A15 (P8.4): integration Test buttons per admin per hour (each is a real provider call)
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
            self::PhotosMaxUploadMb => 10,          // = the 10 MB the uploads used before this was wired (P8.4)
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
            self::BrokerImportAutoPauseMinDecided => 5,       // R-M13-32 applies once this many of a batch's profiles are decided (decisions 2026-10-28)
            self::BrokerImportFileRetentionDays => 30,       // B.9: source files purged after this many days
            self::BrokerPayoutMinimumPaise => 50_000,          // R-A07-5: balances under ₹500 roll forward
            self::BrokerPayoutSecondApproverPaise => 5_000_000,   // R-A07-4: a line above ₹50,000 needs a second approver
            self::ContentHomeToggleMaxPerHour => 10,            // rule 10: each change is an append-only audit row
            self::ContentHomeMembersMin => 6,                  // M12: the featured band stays hidden below this many opted-in members (owner 2026-11-01)
            self::BrokerPayoutTdsBps => 0,                     // B.10 "TDS (if applicable)": 0 until the owner sets the 194H rate (decisions 2026-10-29)
            self::BrokerPayoutReminderDay => 1,                // B.23 payout-run reminders: from this day of the month (IST)
            self::BrokerDisputeUpheldLimit => 3,               // R-A07-10: this many upheld consent disputes …
            self::BrokerDisputeWindowDays => 90,               // … within this many days pause the bureau's create / import rights
            self::BrokerExportAnomalyPerHour => 40,            // B.12: more exports than this by one login in an hour is highlighted
            self::BrokerExportRepeatWindowMinutes => 60,       // B.12: the same profile exported again within this window is highlighted
            self::BrokerSignalSharedSignups => 3,              // B.12 fraud signals (P7.8c): referrals in 30 days from one device / IP …
            self::BrokerSignalSpikeMultiplier => 3,            // … this month ≥ N × the 3-month average …
            self::BrokerSignalSpikeMinimum => 10,              // … and at least this many referrals
            self::BrokerSignalIncompletePct => 60,             // referrals older than 30 days that never went live (min 10)
            self::BrokerSignalRefundCount => 2,                // referred members refunding within 7 days of paying, in 90 days
            self::BrokerSignalRejectionPct => 40,              // A04 rejections of the bureau's profiles in 30 days (min 10 decided)
            self::BrokerSignalStaffDevices => 5,               // devices of one staff login in 7 days (IP addresses: twice this)
            self::BrokerSignalImportSharedPrefix => 5,         // mobiles of one batch sharing their first 8 digits
            self::OtpTtlMinutes => 5,
            self::OtpMaxAttempts => 3,
            self::OtpMaxSendsPer15Minutes => 3,
            self::OtpMaxSendsPerPhonePerDay => 10,
            // PRD §8.2 says 10/IP/day; Indian carriers put many users behind one IP (CGNAT), so the
            // per-phone limits do the real work and this is an abuse backstop (docs/decisions.md 2026-09-29).
            self::OtpMaxSendsPerIpPerDay => 200,
            self::BillingGstRateBps => 1800,        // M10: GST 18 % (basis points) on the pre-GST price after any coupon
            self::BillingOrderTtlMinutes => 30,     // an unpaid checkout order expires after this (a late capture still activates)
            self::BillingCheckoutsMaxPerHour => 20, // rule 10: orders a member may start per hour
            self::BillingCouponAttemptsPerHour => 20, // rule 10: coupon codes a member may try per hour (no guessing)
            self::BillingRefundNotSentWaitMinutes => 30, // A06: an unanswered refund can be closed as "not sent" only this long after approval (owner 2026-11-03)
            // Seller details printed on GST invoices — PLACEHOLDERS until the owner supplies the legal
            // entity (decisions 2026-10-21). An invoice issued without a GSTIN says so on its face.
            self::BillingSellerName => 'Oppam Matrimony',
            self::BillingSellerGstin => '',
            self::BillingSellerAddress => "1st floor 272-3, near St George Basilica church,\nAngamaly, Kerala 683572",
            self::BillingSellerStateCode => 'KL',   // master_states.code — decides CGST + SGST vs IGST
            self::BillingSacCode => '',
            self::VerificationAgingWarnHours => 48,     // A05 aging colours: amber after this long in the queue …
            self::VerificationAgingBreachHours => 72,   // … red (and the A02 card breaches) after this
            self::VerificationPurgeAfterDays => 90,     // M09: originals deleted this long after the decision (never later)
            self::VerificationMaxSubmissionsPerDay => 3, // rule 10: ID submissions per member per day
            self::VerificationRapidResubmitCount => 3,  // A05 fraud signal "rapid resubmission": this many submissions …
            self::VerificationRapidResubmitDays => 7,   // … within this window
            self::SafetyP0SlaHours => 4,                // A10 triage SLAs (decisions 2026-10-23): P0 asking for money / escalated …
            self::SafetyP1SlaHours => 24,               // … P1 abuse, photos, fake profile …
            self::SafetyP2SlaHours => 72,               // … P2 the rest
            self::SafetyEscalateReporters => 3,         // A10 auto-escalation: this many different reporters …
            self::SafetyEscalateWindowDays => 7,        // … within this window → P0
            self::SafetyReportsMaxPerHour => 5,         // M09 / rule 10: profile reports per member per hour
            self::SafetyBlocksMaxPerHour => 30,         // rule 10: blocks + ignores per member per hour
            self::SafetyEvidenceContextMessages => 3,   // A10 reveal: messages shown either side of each reported one
            self::SafetyPhashBanDistance => 6,          // a new photo this close (Hamming bits) to a banned one is flagged for moderation
            self::SafetyOncallPhones => '',             // A10 P0 paging: comma-separated mobile numbers (admins have no phone)
            self::SupportSlaSafetyHours => 4,           // A10 support SLAs (first reply): safety …
            self::SupportSlaPaymentsHours => 24,        // … payments …
            self::SupportSlaDefaultHours => 48,         // … everything else
            self::SupportTicketsMaxPerHour => 5,        // rule 10: tickets per member / visitor per hour
            self::ContactMaxPerHour => 5,               // rule 10: contact-form messages per visitor IP / member per hour (P8.1b)
            self::ContactSpamRetentionDays => 30,       // spam-tab messages are deleted after this
            self::ContactRetentionDays => 365,          // every other contact message is deleted after this
        };
    }

    /** @return list<string> Laravel validation rules for an edit (A15 "editable, validated, audited"). */
    public function rules(): array
    {
        return match ($this) {
            self::SiteName => ['required', 'string', 'max:60'],
            self::SiteLogoPath => ['nullable', 'string', 'max:255', 'regex:/^(site\/logo-[A-Za-z0-9]+\.(png|webp))?$/'],
            self::SocialFacebook, self::SocialTwitter, self::SocialInstagram, self::SocialLinkedin,
            self::SocialYoutube => ['nullable', 'string', 'max:255', 'url:https'],
            self::SeoDefaultTitle => ['required', 'string', 'max:70'],
            self::SeoDefaultDescription => ['required', 'string', 'max:170'],
            self::SiteSupportEmail, self::SiteInfoEmail => ['required', 'email', 'max:255'],
            self::SitePhoneDisplay => ['required', 'string', 'max:40'],
            self::SitePhoneDial => ['required', 'string', 'regex:/^\+?[0-9]{6,15}$/'],
            self::SiteAddress => ['required', 'string', 'max:300'],
            self::SiteHours => ['required', 'string', 'max:120'],
            self::SiteMaintenanceMessage => ['nullable', 'string', 'max:300'],
            self::SeoIndexable => ['required', 'boolean'],
            self::ContentHomeMembersMin => ['required', 'integer', 'min:1', 'max:6'],
            self::ContentHomeToggleMaxPerHour => ['required', 'integer', 'min:1', 'max:100'],
            // Legal minimum marriage ages in India: never below 18 / 21.
            self::MinAgeFemale => ['required', 'integer', 'min:18', 'max:40'],
            self::MinAgeMale => ['required', 'integer', 'min:21', 'max:40'],
            self::ProfileAutoHideInactiveDays => ['required', 'integer', 'min:30', 'max:730'],
            self::MembersPurgeAfterDays => ['required', 'integer', 'min:30', 'max:365'],
            self::MembersSettingsChangesPerHour => ['required', 'integer', 'min:5', 'max:200'],
            self::MembersEmailChangesPerDay => ['required', 'integer', 'min:1', 'max:20'],
            self::NotificationsQuietFrom, self::NotificationsQuietUntil => ['required', 'integer', 'min:0', 'max:23'],
            self::CampaignsApprovalThreshold => ['required', 'integer', 'min:100', 'max:1000000'],
            self::CampaignsMaxPerMinute => ['required', 'integer', 'min:10', 'max:10000'],
            // Longer than the longest journey offset (365), so a step is never repeated after its row is pruned.
            self::JourneysLedgerRetentionDays => ['required', 'integer', 'min:370', 'max:1825'],
            self::SettingsIntegrationChecksPerHour => ['required', 'integer', 'min:1', 'max:100'],
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
            self::BrokerImportAutoPauseMinDecided => ['required', 'integer', 'min:1', 'max:500'],
            self::BrokerImportFileRetentionDays => ['required', 'integer', 'min:1', 'max:365'],
            self::BrokerPayoutMinimumPaise => ['required', 'integer', 'min:0', 'max:10000000'],
            self::BrokerPayoutSecondApproverPaise => ['required', 'integer', 'min:100', 'max:100000000'],
            self::BrokerPayoutTdsBps => ['required', 'integer', 'min:0', 'max:3000'],
            self::BrokerPayoutReminderDay => ['required', 'integer', 'min:1', 'max:28'],
            self::BrokerDisputeUpheldLimit => ['required', 'integer', 'min:1', 'max:50'],
            self::BrokerDisputeWindowDays => ['required', 'integer', 'min:7', 'max:365'],
            self::BrokerExportAnomalyPerHour => ['required', 'integer', 'min:5', 'max:1000'],
            self::BrokerExportRepeatWindowMinutes => ['required', 'integer', 'min:5', 'max:1440'],
            self::BrokerSignalSharedSignups, self::BrokerSignalRefundCount, self::BrokerSignalStaffDevices,
            self::BrokerSignalImportSharedPrefix => ['required', 'integer', 'min:2', 'max:100'],
            self::BrokerSignalSpikeMultiplier => ['required', 'integer', 'min:2', 'max:20'],
            self::BrokerSignalSpikeMinimum => ['required', 'integer', 'min:1', 'max:1000'],
            self::BrokerSignalIncompletePct, self::BrokerSignalRejectionPct => ['required', 'integer', 'min:1', 'max:100'],
            self::OtpTtlMinutes => ['required', 'integer', 'min:1', 'max:30'],
            self::OtpMaxAttempts, self::OtpMaxSendsPer15Minutes => ['required', 'integer', 'min:1', 'max:10'],
            self::OtpMaxSendsPerPhonePerDay => ['required', 'integer', 'min:1', 'max:50'],
            self::OtpMaxSendsPerIpPerDay => ['required', 'integer', 'min:10', 'max:5000'],
            self::BillingGstRateBps => ['required', 'integer', 'min:0', 'max:2800'],
            self::BillingOrderTtlMinutes => ['required', 'integer', 'min:5', 'max:1440'],
            self::BillingCheckoutsMaxPerHour, self::BillingCouponAttemptsPerHour => ['required', 'integer', 'min:3', 'max:200'],
            self::BillingRefundNotSentWaitMinutes => ['required', 'integer', 'min:10', 'max:1440'],
            self::BillingSellerName => ['required', 'string', 'max:150'],
            self::BillingSellerGstin => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            self::BillingSellerAddress => ['required', 'string', 'max:300'],
            self::BillingSellerStateCode => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            self::BillingSacCode => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            self::VerificationAgingWarnHours, self::VerificationAgingBreachHours => ['required', 'integer', 'min:1', 'max:720'],
            // M09 "originals deleted 90 days after decision": an admin may shorten it, never lengthen it.
            self::VerificationPurgeAfterDays => ['required', 'integer', 'min:7', 'max:90'],
            self::VerificationMaxSubmissionsPerDay => ['required', 'integer', 'min:1', 'max:20'],
            self::VerificationRapidResubmitCount => ['required', 'integer', 'min:2', 'max:20'],
            self::VerificationRapidResubmitDays => ['required', 'integer', 'min:1', 'max:90'],
            self::SafetyP0SlaHours => ['required', 'integer', 'min:1', 'max:48'],
            self::SafetyP1SlaHours, self::SafetyP2SlaHours => ['required', 'integer', 'min:1', 'max:336'],
            self::SafetyEscalateReporters => ['required', 'integer', 'min:2', 'max:20'],
            self::SafetyEscalateWindowDays => ['required', 'integer', 'min:1', 'max:90'],
            self::SafetyReportsMaxPerHour => ['required', 'integer', 'min:1', 'max:50'],
            self::SafetyBlocksMaxPerHour => ['required', 'integer', 'min:5', 'max:500'],
            self::SafetyEvidenceContextMessages => ['required', 'integer', 'min:0', 'max:10'],
            self::SafetyPhashBanDistance => ['required', 'integer', 'min:0', 'max:16'],
            self::SafetyOncallPhones => ['nullable', 'string', 'max:300', 'regex:/^(\+?[0-9]{10,15})?(\s*,\s*\+?[0-9]{10,15})*$/'],
            self::SupportSlaSafetyHours, self::SupportSlaPaymentsHours, self::SupportSlaDefaultHours => ['required', 'integer', 'min:1', 'max:336'],
            self::SupportTicketsMaxPerHour => ['required', 'integer', 'min:1', 'max:50'],
            self::ContactMaxPerHour => ['required', 'integer', 'min:1', 'max:50'],
            self::ContactSpamRetentionDays => ['required', 'integer', 'min:1', 'max:365'],
            self::ContactRetentionDays => ['required', 'integer', 'min:30', 'max:3650'],
        };
    }

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    /** The A15 form control for this key: 'checkbox' | 'number' | 'url' | 'textarea' | 'text'. */
    public function inputKind(): string
    {
        return match (true) {
            $this->type() === SettingType::Boolean => 'checkbox',
            $this->type() === SettingType::Integer => 'number',
            $this->group() === 'social' => 'url',
            in_array($this, [self::SiteAddress, self::BillingSellerAddress, self::SeoDefaultDescription, self::SiteMaintenanceMessage], true) => 'textarea',
            default => 'text',
        };
    }

    /** Edited on A15 "Site settings" (P8.4); everything else is a business rule. */
    public function isSite(): bool
    {
        return in_array($this->group(), ['site', 'seo', 'social', 'content'], true);
    }

    /** The A15 business-rules section this key is listed under. */
    public function section(): string
    {
        return match ($this->group()) {
            'site', 'seo', 'social', 'content' => __('Site'),
            'profile', 'members', 'photos' => __('Members & profiles'),
            'notifications', 'campaigns', 'journeys' => __('Notifications & campaigns'),
            'interest', 'interests', 'likes', 'favorites', 'search', 'profile_views', 'matching' => __('Engagement & matching'),
            'chat' => __('Chat'),
            'signals', 'moderation', 'system', 'settings' => __('Moderation & monitoring'),
            'broker' => __('Brokers & bureaus'),
            'otp' => __('Sign-in (OTP)'),
            'billing' => __('Billing & invoices'),
            'verification' => __('ID verification'),
            default => __('Safety & support'),
        };
    }

    /** What the number counts, from the key's own name (days, minutes, %, …); '' for text and plain counts. */
    public function unit(): string
    {
        if ($this->type() !== SettingType::Integer) {
            return '';
        }

        return match (true) {
            str_ends_with($this->value, '_days') => __('days'),
            str_ends_with($this->value, '_hours') => __('hours'),
            str_ends_with($this->value, '_minutes') => __('minutes'),
            str_ends_with($this->value, '_pct') => '%',
            str_ends_with($this->value, '_mb') => 'MB',
            str_ends_with($this->value, '_paise') => __('paise (₹1 = 100)'),
            str_ends_with($this->value, '_bps') => __('basis points (1% = 100)'),
            str_contains($this->value, 'per_minute') => __('per minute'),
            str_contains($this->value, 'per_hour') => __('per hour'),
            str_contains($this->value, 'per_day') => __('per day'),
            in_array($this, [self::NotificationsQuietFrom, self::NotificationsQuietUntil], true) => __('hour (0–23, IST)'),
            in_array($this, [self::MinAgeFemale, self::MinAgeMale], true) => __('years'),
            default => '',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SiteName => __('Site name'),
            self::SiteLogoPath => __('Logo'),
            self::SocialFacebook => __('Facebook page'),
            self::SocialTwitter => __('X (Twitter) profile'),
            self::SocialInstagram => __('Instagram profile'),
            self::SocialLinkedin => __('LinkedIn page'),
            self::SocialYoutube => __('YouTube channel'),
            self::SeoDefaultTitle => __('Default page title'),
            self::SeoDefaultDescription => __('Default page description'),
            self::SiteSupportEmail => __('Support email'),
            self::SiteInfoEmail => __('Information email'),
            self::SitePhoneDisplay => __('Phone (as shown)'),
            self::SitePhoneDial => __('Phone (to dial)'),
            self::SiteAddress => __('Office address'),
            self::SiteHours => __('Office hours'),
            self::SiteMaintenanceMessage => __('Maintenance message'),
            self::SeoIndexable => __('Let search engines index the site'),
            self::ContentHomeMembersMin => __('Featured members needed before the home band shows'),
            self::ContentHomeToggleMaxPerHour => __('Featured on/off changes a member may make'),
            self::MinAgeFemale => __('Minimum age — bride'),
            self::MinAgeMale => __('Minimum age — groom'),
            self::ProfileAutoHideInactiveDays => __('Hide profiles inactive for'),
            self::MembersPurgeAfterDays => __('Deleted accounts restorable for'),
            self::MembersSettingsChangesPerHour => __('Settings saves a member may make'),
            self::MembersEmailChangesPerDay => __('Email confirmation links a member may ask for'),
            self::NotificationsQuietFrom => __('SMS quiet hours start'),
            self::NotificationsQuietUntil => __('SMS quiet hours end'),
            self::CampaignsApprovalThreshold => __('Campaign size needing a second admin'),
            self::CampaignsMaxPerMinute => __('Highest campaign sending speed'),
            self::JourneysLedgerRetentionDays => __('Keep the journey "already sent" record for'),
            self::SettingsIntegrationChecksPerHour => __('Integration tests an admin may run'),
            self::InterestExpiryDays => __('Interest expires after'),
            self::InterestResendCooldownDays => __('Wait before re-sending an interest'),
            self::ChatUnsendWindowMinutes => __('A message can be unsent for'),
            self::ChatMaxPerMinute => __('Chat messages a member may send'),
            self::ChatMaxLength => __('Longest chat message (characters)'),
            self::ChatImageMaxMb => __('Largest chat photo'),
            self::ChatRetentionDays => __('Delete threads idle for'),
            self::ChatDigestDelayMinutes => __('Email unread messages after'),
            self::ChatSafetyFirstMessages => __('Messages with contact details masked'),
            self::ChatSpamRepeatThreads => __('Same text in this many threads = spam'),
            self::ChatSpamRepeatWindowMinutes => __('… within'),
            self::ChatSpamRepeatMinLength => __('Shortest text counted as a repeat (characters)'),
            self::ChatAutoFreezeReports => __('Chat reports that freeze a sender'),
            self::ChatAutoFreezeWindowDays => __('… within'),
            self::ChatReportsMaxPerHour => __('Chat reports a member may make'),
            self::ChatBreakGlassMinutes => __('Break-glass read lasts'),
            self::ChatOneSidedThreshold => __('Unanswered messages in a row = harassment signal'),
            self::SignalsInterestSpamMinSent => __('Interest spam: at least this many sent'),
            self::SignalsInterestSpamWindowDays => __('Interest spam: within'),
            self::SignalsInterestSpamMaxAcceptPct => __('Interest spam: at most this share accepted'),
            self::SignalsNewAccountDays => __('New account: younger than'),
            self::SignalsNewAccountThreadsPerDay => __('New account: conversations a day = signal'),
            self::DailyMatchCount => __('Daily matches per member'),
            self::PhotosMaxAdditional => __('Photos besides the main one'),
            self::PhotosMaxUploadMb => __('Largest profile photo'),
            self::LikesSpamMaxPer10Minutes => __('Likes in 10 minutes before a pause'),
            self::LikesSpamBlockMinutes => __('Like pause lasts'),
            self::FavoritesMaxPerMinute => __('Favourite changes a member may make'),
            self::InterestsMaxPerMinute => __('Interests a member may send'),
            self::SearchMaxPerMinute => __('Searches a member may run'),
            self::ProfileViewsMaxPerMinute => __('Profile views counted'),
            self::ModerationSlaHours => __('Moderation item overdue after'),
            self::SystemQueueBacklogAlert => __('Queue backlog alert at (jobs waiting)'),
            self::BrokerStaffSeatsDefault => __('Staff seats for a new bureau'),
            self::BrokerImportRowsPerFile => __('Rows per import file'),
            self::BrokerImportRowsPerDay => __('Import rows a bureau may add'),
            self::BrokerImportRowsPerDayNew => __('Import rows a new bureau may add'),
            self::BrokerImportAutoPauseRejectionPct => __('Pause imports when this share is rejected'),
            self::BrokerImportAutoPauseMinDecided => __('… once this many are decided'),
            self::BrokerImportFileRetentionDays => __('Keep import files for'),
            self::BrokerPayoutMinimumPaise => __('Smallest payout'),
            self::BrokerPayoutSecondApproverPaise => __('Payout line needing a second approver'),
            self::BrokerPayoutTdsBps => __('TDS on payouts'),
            self::BrokerPayoutReminderDay => __('Payout reminder from day of month'),
            self::BrokerDisputeUpheldLimit => __('Upheld consent disputes that pause a bureau'),
            self::BrokerDisputeWindowDays => __('… within'),
            self::BrokerExportAnomalyPerHour => __('Exports by one login an hour = anomaly'),
            self::BrokerExportRepeatWindowMinutes => __('Same profile exported again within'),
            self::BrokerSignalSharedSignups => __('Fraud: referrals from one device / IP'),
            self::BrokerSignalSpikeMultiplier => __('Fraud: referral spike (× the average)'),
            self::BrokerSignalSpikeMinimum => __('Fraud: spike needs at least this many referrals'),
            self::BrokerSignalIncompletePct => __('Fraud: referrals never live'),
            self::BrokerSignalRefundCount => __('Fraud: quick refunds by referred members'),
            self::BrokerSignalRejectionPct => __('Quality: profiles rejected'),
            self::BrokerSignalStaffDevices => __('Security: devices of one staff login in 7 days'),
            self::BrokerSignalImportSharedPrefix => __('Fraud: imported mobiles sharing a prefix'),
            self::OtpTtlMinutes => __('OTP valid for'),
            self::OtpMaxAttempts => __('Wrong OTP tries allowed'),
            self::OtpMaxSendsPer15Minutes => __('OTPs to one number in 15 minutes'),
            self::OtpMaxSendsPerPhonePerDay => __('OTPs to one number'),
            self::OtpMaxSendsPerIpPerDay => __('OTPs from one IP address'),
            self::BillingGstRateBps => __('GST rate'),
            self::BillingOrderTtlMinutes => __('Unpaid checkout expires after'),
            self::BillingCheckoutsMaxPerHour => __('Checkouts a member may start'),
            self::BillingCouponAttemptsPerHour => __('Coupon codes a member may try'),
            self::BillingRefundNotSentWaitMinutes => __('Close an unanswered refund after'),
            self::BillingSellerName => __('Invoice: seller name'),
            self::BillingSellerGstin => __('Invoice: seller GSTIN'),
            self::BillingSellerAddress => __('Invoice: seller address'),
            self::BillingSellerStateCode => __('Invoice: seller state code'),
            self::BillingSacCode => __('Invoice: SAC code'),
            self::VerificationAgingWarnHours => __('ID queue amber after'),
            self::VerificationAgingBreachHours => __('ID queue red after'),
            self::VerificationPurgeAfterDays => __('Delete ID documents after the decision'),
            self::VerificationMaxSubmissionsPerDay => __('ID submissions a member may make'),
            self::VerificationRapidResubmitCount => __('Rapid resubmission: this many tries'),
            self::VerificationRapidResubmitDays => __('… within'),
            self::SafetyP0SlaHours => __('P0 safety reports answered within'),
            self::SafetyP1SlaHours => __('P1 safety reports answered within'),
            self::SafetyP2SlaHours => __('P2 safety reports answered within'),
            self::SafetyEscalateReporters => __('Reporters that escalate to P0'),
            self::SafetyEscalateWindowDays => __('… within'),
            self::SafetyReportsMaxPerHour => __('Profile reports a member may make'),
            self::SafetyBlocksMaxPerHour => __('Blocks and ignores a member may make'),
            self::SafetyEvidenceContextMessages => __('Messages shown around a reported one'),
            self::SafetyPhashBanDistance => __('Photo similarity to a banned photo (bits)'),
            self::SafetyOncallPhones => __('On-call mobiles for P0 alerts'),
            self::SupportSlaSafetyHours => __('Support: safety tickets answered within'),
            self::SupportSlaPaymentsHours => __('Support: payment tickets answered within'),
            self::SupportSlaDefaultHours => __('Support: other tickets answered within'),
            self::SupportTicketsMaxPerHour => __('Support tickets one person may open'),
            self::ContactMaxPerHour => __('Contact-form messages one person may send'),
            self::ContactSpamRetentionDays => __('Delete contact spam after'),
            self::ContactRetentionDays => __('Delete contact messages after'),
        };
    }
}
