<?php

declare(strict_types=1);

use App\Enums\OtpPurpose;
use App\Services\Broker\StaffCodeGenerator;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| P7.5 — staff accounts (§11A B.8, decisions 2026-10-26):
|  - `broker_staff.code` (STF1001…): the public id in /broker/staff/{code} — URLs never carry ULIDs;
|    existing rows get one here, new rows from StaffCodeGenerator (BrokerStaff::creating);
|  - `broker_staff.name`: the name the Owner typed on the invitation (the Owner row shows the bureau's);
|  - OTP purpose STAFF_INVITE (accepting an invitation proves the invited mobile);
|  - an index for "is there an open invitation for this number in this bureau".
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_staff', function (Blueprint $table): void {
            $table->string('code', 12)->nullable()->unique()->after('id');
            $table->string('name', 100)->nullable()->after('user_id');
        });

        $codes = app(StaffCodeGenerator::class);
        DB::table('broker_staff')->whereNull('code')->orderBy('created_at')->orderBy('id')->pluck('id')
            ->each(fn (string $id) => DB::table('broker_staff')->where('id', $id)->update(['code' => $codes->next()]));

        Schema::table('broker_staff_invitations', function (Blueprint $table): void {
            $table->index(['broker_id', 'phone']);
        });

        $this->replaceOtpPurposeCheck();
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('otp_challenges')->where('purpose', 'STAFF_INVITE')->delete();
            DB::statement('ALTER TABLE `otp_challenges` DROP CONSTRAINT `chk_otp_challenges_purpose`');
            DB::statement("ALTER TABLE `otp_challenges` ADD CONSTRAINT `chk_otp_challenges_purpose` CHECK (`purpose` IN ('REGISTER', 'LOGIN', 'PASSWORD_RESET', 'CHANGE_PHONE', 'BROKER_DEVICE'))");
        }

        Schema::table('broker_staff_invitations', function (Blueprint $table): void {
            $table->dropIndex(['broker_id', 'phone']);
        });

        Schema::table('broker_staff', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'name']);
        });
    }

    private function replaceOtpPurposeCheck(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `otp_challenges` DROP CONSTRAINT `chk_otp_challenges_purpose`');
            EnumCheck::add('otp_challenges', 'purpose', OtpPurpose::class);
        }
    }
};
