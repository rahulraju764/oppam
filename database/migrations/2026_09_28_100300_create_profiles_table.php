<?php

declare(strict_types=1);

use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Enums\ProfileStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The matrimony profile (PRD §7.2). `code` (OPM10001) is public and used in URLs; the ULID never
| leaves the server. Broker columns (owner_type, managed_by_broker_id, broker_id, …) are added
| in P7.1 together with the brokers table they reference (docs/decisions.md).
|
| A DRAFT profile is created at registration (M01, P1.1) with only the name and gender; the
| wizard's step-1 basics (last name, DOB, height, marital status, religion, mother tongue) are
| nullable while the profile is DRAFT and a CHECK makes them mandatory for every other status
| (docs/decisions.md 2026-09-29).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Nullable: broker-managed profiles have no user until claimed (§11A B.6).
            $table->foreignUlid('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('code', 12)->unique();
            $table->string('gender', 10);
            $table->string('first_name', 60);
            $table->string('last_name', 60)->nullable();          // required once not DRAFT (CHECK below)
            $table->date('dob')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->unsignedSmallInteger('weight_kg')->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->unsignedTinyInteger('children_count')->default(0);
            $table->string('physical_status', 30)->default(PhysicalStatus::Normal->value);
            $table->foreignId('religion_id')->nullable()->constrained('master_religions')->restrictOnDelete();
            $table->foreignId('caste_id')->nullable()->constrained('master_castes')->restrictOnDelete();
            $table->boolean('caste_no_bar')->default(false);
            $table->string('sub_caste', 80)->nullable();
            $table->foreignId('mother_tongue_id')->nullable()->constrained('master_mother_tongues')->restrictOnDelete();
            $table->foreignId('star_id')->nullable()->constrained('master_stars')->restrictOnDelete();
            $table->foreignId('rasi_id')->nullable()->constrained('master_rasis')->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('master_districts')->restrictOnDelete();
            $table->string('about', 1000)->nullable();
            $table->string('status', 20)->default(ProfileStatus::Draft->value);
            $table->boolean('is_verified')->default(false);       // ID-verified badge (M09)
            $table->boolean('is_premium')->default(false);        // denormalised from subscriptions (P5)
            $table->timestamp('highlighted_until')->nullable();   // boost / featured
            $table->unsignedTinyInteger('completeness')->default(0);   // R-M02-3, 0–100
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Search indexes (PRD §7.2): equality filters first, then the range.
            $table->index(['status', 'gender', 'dob']);
            $table->index(['status', 'gender', 'religion_id', 'caste_id']);
            $table->index(['status', 'gender', 'district_id']);
        });

        EnumCheck::add('profiles', 'gender', Gender::class);
        EnumCheck::add('profiles', 'marital_status', MaritalStatus::class, nullable: true);
        EnumCheck::add('profiles', 'physical_status', PhysicalStatus::class);
        EnumCheck::add('profiles', 'status', ProfileStatus::class);

        // Only a DRAFT may lack the step-1 basics (M02 step 1 fills them before submit).
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `profiles` ADD CONSTRAINT `chk_profiles_basics_unless_draft` CHECK (
                `status` = 'DRAFT' OR (`last_name` IS NOT NULL AND `dob` IS NOT NULL AND `height_cm` IS NOT NULL
                AND `marital_status` IS NOT NULL AND `religion_id` IS NOT NULL AND `mother_tongue_id` IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
