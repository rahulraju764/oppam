<?php

declare(strict_types=1);

use App\Enums\BrokerExportType;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ClientNoteType;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Enums\KycSubmissionStatus;
use App\Enums\OtpPurpose;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Enums\ProfileOwnerType;
use App\Enums\ProfileScope;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Broker / bureau module data model (PRD §11A B.13 — P7.1). Every table of the module is created
| now; the screens that use most of them arrive in P7.2 … P7.8.
| Deviations from the B.13 sketch (docs/decisions.md 2026-10-23): enums are strings (project
| convention); `brokers.district` is `district_id` (master data, never free text); the PAN typed at
| application is kept only as a keyed hash + last 4 (one PAN = one bureau); KYC secrets use the
| `encrypted` cast (text columns), the PAN image lives encrypted in the DocumentVault; payout
| tables are filled in from B.13's comment (v4 A07 is not available). `trusted_devices` backs
| "OTP on each new device" for Owners and Managers (§8.1). ULID keys; financial / audit rows are
| never cascaded away.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brokers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 12)->unique();                 // BRK1042 — system-generated (R-A07-1)
            $table->string('name', 100);                          // the Owner's name
            $table->string('bureau_name', 120)->nullable();
            $table->string('phone', 16);
            $table->string('email')->nullable();
            $table->foreignId('district_id')->nullable()->constrained('master_districts')->restrictOnDelete();
            $table->unsignedSmallInteger('commission_bps')->default(1000);
            $table->string('status', 12)->default('APPLIED');
            $table->string('kyc_status', 14)->default('NOT_SUBMITTED');
            $table->char('pan_hash', 64)->nullable()->unique();
            $table->char('pan_last4', 4)->nullable();
            $table->unsignedTinyInteger('staff_seat_limit')->default(5);
            $table->unsignedSmallInteger('import_daily_cap')->default(1000);
            $table->unsignedSmallInteger('interest_allowance_monthly')->default(100);
            $table->timestamp('imports_paused_at')->nullable();
            $table->string('status_reason', 300)->nullable();     // why REJECTED / SUSPENDED (admin-facing)
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('broker_kyc', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('broker_id')->constrained('brokers');
            $table->text('pan_number_encrypted');
            $table->char('pan_last4', 4);
            $table->string('pan_image_path', 100)->nullable();    // DocumentVault (encrypted, private disk)
            $table->string('pan_image_mime', 40)->nullable();
            $table->text('bank_account_encrypted');
            $table->char('bank_last4', 4);
            $table->char('ifsc', 11);
            $table->string('account_name', 100);
            $table->string('status', 10);
            $table->foreignUlid('reviewed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'status']);
            $table->index(['status', 'created_at']);              // the A07 KYC queue
        });

        Schema::create('broker_staff', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('broker_id')->constrained('brokers');
            $table->foreignUlid('user_id')->unique()->constrained();   // one bureau per login (R-M13-22)
            $table->string('role', 12);
            $table->string('profile_scope', 10)->default('ALL');
            $table->json('disabled_permissions')->nullable();
            $table->string('status', 12)->default('INVITED');
            $table->foreignUlid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'status']);
        });

        Schema::create('broker_staff_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('broker_id')->constrained('brokers');
            $table->string('phone', 16);
            $table->string('name', 100);
            $table->string('role', 12);
            $table->string('profile_scope', 10);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->useCurrent();   // explicit default: MariaDB implicit ON UPDATE
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'accepted_at']);
        });

        Schema::create('broker_payout_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 10)->default('DRAFT');
            $table->foreignUlid('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignUlid('approved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignUlid('second_approved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['period_start', 'period_end']);
        });

        Schema::create('broker_payout_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('broker_payout_runs');
            $table->foreignUlid('broker_id')->constrained('brokers');
            $table->unsignedInteger('gross_paise')->default(0);
            $table->unsignedInteger('clawback_paise')->default(0);
            $table->integer('carried_forward_paise')->default(0);
            $table->integer('net_paise')->default(0);
            $table->string('utr', 40)->nullable();
            $table->string('status', 10)->default('PENDING');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'broker_id']);
            $table->index(['broker_id', 'status']);
        });

        Schema::create('broker_import_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 12)->unique();                 // B-0042
            $table->foreignUlid('broker_id')->constrained('brokers');
            $table->foreignUlid('uploaded_by_user_id')->constrained('users');
            $table->string('sheet_path', 100)->nullable();        // private, purged at 30 days
            $table->string('photos_zip_path', 100)->nullable();
            $table->char('file_sha256', 64)->index();
            $table->json('column_map')->nullable();
            $table->string('status', 12);
            $table->unsignedSmallInteger('rows_total')->default(0);
            $table->unsignedSmallInteger('rows_valid')->default(0);
            $table->unsignedSmallInteger('rows_warning')->default(0);
            $table->unsignedSmallInteger('rows_error')->default(0);
            $table->unsignedSmallInteger('rows_imported')->default(0);
            $table->timestamp('consent_attested_at')->nullable();
            $table->foreignUlid('consent_attested_by_user_id')->nullable()->constrained('users');
            $table->timestamp('files_purged_at')->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'created_at']);
        });

        Schema::table('profiles', function (Blueprint $table): void {
            // Referral attribution (B.5, R-M13-3): first-touch, permanent.
            $table->foreignUlid('broker_id')->nullable()->after('user_id')->constrained('brokers')->nullOnDelete();
            $table->string('broker_code_raw', 20)->nullable()->after('broker_id');
            $table->boolean('broker_verified')->default(false)->after('broker_code_raw');
            // Ownership (B.6): SELF or BROKER_MANAGED by one bureau until claimed.
            $table->string('owner_type', 16)->default('SELF')->after('broker_verified');
            $table->foreignUlid('managed_by_broker_id')->nullable()->after('owner_type')->constrained('brokers')->nullOnDelete();
            $table->timestamp('broker_consent_confirmed_at')->nullable()->after('managed_by_broker_id');
            $table->foreignUlid('consent_confirmed_by_user_id')->nullable()->after('broker_consent_confirmed_at')->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->after('consent_confirmed_by_user_id')->constrained('users')->nullOnDelete();
            $table->foreignUlid('assigned_staff_id')->nullable()->after('created_by_user_id')->constrained('broker_staff')->nullOnDelete();
            $table->foreignUlid('import_batch_id')->nullable()->after('assigned_staff_id')->constrained('broker_import_batches')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('import_batch_id');

            $table->index(['managed_by_broker_id', 'status']);
            $table->index(['managed_by_broker_id', 'assigned_staff_id']);
        });

        Schema::create('broker_referrals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('broker_id')->constrained('brokers');
            $table->foreignUlid('profile_id')->unique()->constrained('profiles');   // one commission per profile
            $table->string('source', 10)->default('REFERRAL');
            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedInteger('commission_paise')->default(0);
            $table->string('status', 10)->default('PENDING');
            $table->foreignUlid('payout_item_id')->nullable()->constrained('broker_payout_items')->nullOnDelete();
            $table->timestamps();

            $table->index(['broker_id', 'status']);
        });

        Schema::create('broker_profile_exports', function (Blueprint $table): void {   // append-only (R-A07-7)
            $table->ulid('id')->primary();
            $table->foreignUlid('broker_id')->constrained('brokers');
            $table->foreignUlid('user_id')->constrained('users');               // which staff member (R-M13-23)
            $table->foreignUlid('profile_id')->constrained('profiles');
            $table->string('export_type', 14);
            $table->boolean('allowed');                                         // denied attempts too (R-M13-13)
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['broker_id', 'created_at']);
            $table->index(['profile_id', 'created_at']);
        });

        Schema::create('broker_client_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUlid('broker_id')->constrained('brokers');
            $table->foreignUlid('author_user_id')->constrained('users');
            $table->string('type', 8);
            $table->string('outcome', 40)->nullable();
            $table->text('body');
            $table->date('follow_up_on')->nullable()->index();
            $table->timestamp('follow_up_done_at')->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'profile_id', 'created_at']);
        });

        Schema::create('broker_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('batch_id')->constrained('broker_import_batches')->cascadeOnDelete();
            $table->unsignedSmallInteger('row_number');
            $table->json('data');
            $table->string('status', 10);
            $table->json('messages')->nullable();                               // [{field, level, text}]
            $table->foreignUlid('imported_profile_id')->nullable()->constrained('profiles')->nullOnDelete();   // idempotency (R-M13-33)
            $table->timestamps();

            $table->unique(['batch_id', 'row_number']);
        });

        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->timestamp('verified_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_hash']);
        });

        // The database refuses values the code would never write (EnumCheck, laravel-patterns.md).
        foreach ([
            ['brokers', 'status', BrokerStatus::class], ['brokers', 'kyc_status', BrokerKycStatus::class],
            ['broker_kyc', 'status', KycSubmissionStatus::class],
            ['broker_staff', 'role', BureauRole::class], ['broker_staff', 'profile_scope', ProfileScope::class], ['broker_staff', 'status', BureauStaffStatus::class],
            ['broker_staff_invitations', 'role', BureauRole::class], ['broker_staff_invitations', 'profile_scope', ProfileScope::class],
            ['broker_payout_runs', 'status', PayoutRunStatus::class], ['broker_payout_items', 'status', PayoutItemStatus::class],
            ['broker_import_batches', 'status', ImportBatchStatus::class], ['broker_import_rows', 'status', ImportRowStatus::class],
            ['broker_referrals', 'source', ReferralSource::class], ['broker_referrals', 'status', ReferralStatus::class],
            ['broker_profile_exports', 'export_type', BrokerExportType::class], ['broker_client_notes', 'type', ClientNoteType::class],
            ['profiles', 'owner_type', ProfileOwnerType::class],
        ] as [$table, $column, $enum]) {
            EnumCheck::add($table, $column, $enum);
        }

        // New OTP purpose BROKER_DEVICE (§8.1 "OTP on each new device"): widen the existing CHECK.
        $this->replaceOtpPurposeCheck();
    }

    private function replaceOtpPurposeCheck(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `otp_challenges` DROP CONSTRAINT `chk_otp_challenges_purpose`');
            EnumCheck::add('otp_challenges', 'purpose', OtpPurpose::class);
        }
    }

    public function down(): void
    {
        // Back to the purposes the earlier migration allowed (no BROKER_DEVICE rows may remain).
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('otp_challenges')->where('purpose', 'BROKER_DEVICE')->delete();
            DB::statement('ALTER TABLE `otp_challenges` DROP CONSTRAINT `chk_otp_challenges_purpose`');
            DB::statement("ALTER TABLE `otp_challenges` ADD CONSTRAINT `chk_otp_challenges_purpose` CHECK (`purpose` IN ('REGISTER', 'LOGIN', 'PASSWORD_RESET', 'CHANGE_PHONE'))");
        }

        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('broker_import_rows');
        Schema::dropIfExists('broker_client_notes');
        Schema::dropIfExists('broker_profile_exports');
        Schema::dropIfExists('broker_referrals');

        Schema::table('profiles', function (Blueprint $table): void {
            $keys = ['broker_id', 'managed_by_broker_id', 'consent_confirmed_by_user_id', 'created_by_user_id', 'assigned_staff_id', 'import_batch_id'];
            foreach ($keys as $column) {
                $table->dropForeign([$column]);
            }
            $table->dropIndex(['managed_by_broker_id', 'status']);
            $table->dropIndex(['managed_by_broker_id', 'assigned_staff_id']);
            $table->dropColumn([...$keys, 'broker_code_raw', 'broker_verified', 'owner_type', 'broker_consent_confirmed_at', 'claimed_at']);
        });

        Schema::dropIfExists('broker_import_batches');
        Schema::dropIfExists('broker_payout_items');
        Schema::dropIfExists('broker_payout_runs');
        Schema::dropIfExists('broker_staff_invitations');
        Schema::dropIfExists('broker_staff');
        Schema::dropIfExists('broker_kyc');
        Schema::dropIfExists('brokers');
    }
};
