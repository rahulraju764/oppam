<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The bureau permission keys and each role's CEILING (PRD §11A B.2 matrix, fixed presets in v1).
 * A staff member's effective permissions are the ceiling minus the Owner's switch-offs
 * (R-M13-19) — BureauGate decides, on every request. `kyc.manage` is the matrix's "bank & KYC"
 * and `activity.view` the B.11 activity log ("Owner, Manager").
 */
enum BureauPermission: string
{
    case DashboardView = 'bureau.dashboard.view';
    case ProfilesView = 'profiles.view';
    case ProfilesCreate = 'profiles.create';
    case ProfilesEdit = 'profiles.edit';
    case ProfilesImport = 'profiles.import';
    case ProfilesSubmit = 'profiles.submit_for_review';
    case ProfilesActivate = 'profiles.activate';
    case ProfilesDeactivate = 'profiles.deactivate';
    case ProfilesDelete = 'profiles.delete';
    case ProfilesAssign = 'profiles.assign';
    case ClientInboxRespond = 'client_inbox.respond';
    case ClientNotesWrite = 'client_notes.write';
    case ProfilesExport = 'profiles.export';
    case ReferralsView = 'referrals.view';
    case EarningsView = 'earnings.view';
    case KycManage = 'kyc.manage';
    case StaffManage = 'staff.manage';
    case MaterialsView = 'materials.view';
    case ActivityView = 'activity.view';

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => __('Dashboard'),
            self::ProfilesView => __('View profiles'),
            self::ProfilesCreate => __('Create profiles'),
            self::ProfilesEdit => __('Edit profiles'),
            self::ProfilesImport => __('Bulk import'),
            self::ProfilesSubmit => __('Submit for review'),
            self::ProfilesActivate => __('Activate profiles'),
            self::ProfilesDeactivate => __('Deactivate profiles'),
            self::ProfilesDelete => __('Delete profiles'),
            self::ProfilesAssign => __('Assign profiles to staff'),
            self::ClientInboxRespond => __('Answer the client inbox'),
            self::ClientNotesWrite => __('Client notes & reminders'),
            self::ProfilesExport => __('Export biodata & photos'),
            self::ReferralsView => __('Referrals'),
            self::EarningsView => __('Earnings & payouts'),
            self::KycManage => __('KYC & bank details'),
            self::StaffManage => __('Manage staff'),
            self::MaterialsView => __('Referral materials'),
            self::ActivityView => __('Activity log'),
        };
    }

    /**
     * The B.2 matrix: what each role may do at most.
     *
     * @return list<self>
     */
    public static function ceiling(BureauRole $role): array
    {
        return match ($role) {
            BureauRole::Owner => self::cases(),
            BureauRole::Manager => [
                self::DashboardView, self::ProfilesView, self::ProfilesCreate, self::ProfilesEdit, self::ProfilesImport,
                self::ProfilesSubmit, self::ProfilesActivate, self::ProfilesDeactivate, self::ProfilesDelete, self::ProfilesAssign,
                self::ClientInboxRespond, self::ClientNotesWrite, self::ProfilesExport, self::ReferralsView, self::MaterialsView,
                self::ActivityView,
            ],
            BureauRole::DataEntry => [
                self::DashboardView, self::ProfilesView, self::ProfilesCreate, self::ProfilesEdit, self::ProfilesImport,
                self::ProfilesSubmit, self::ClientNotesWrite, self::MaterialsView,
            ],
            BureauRole::Telecaller => [
                self::DashboardView, self::ProfilesView, self::ClientInboxRespond, self::ClientNotesWrite, self::MaterialsView,
            ],
        };
    }

    /**
     * Handling someone else's data needs an ACTIVE, KYC-verified bureau (R-M13-10, R-M13-21,
     * R-A07-2): creating, importing and exporting profiles. Everything else works before KYC.
     */
    public function needsVerifiedBureau(): bool
    {
        return in_array($this, [self::ProfilesCreate, self::ProfilesImport, self::ProfilesExport], true);
    }

    /** Owner-only keys can never be switched off — nor granted to staff (R-M13-24). */
    public function isOwnerOnly(): bool
    {
        return in_array($this, [self::EarningsView, self::KycManage, self::StaffManage], true);
    }
}
