<?php

declare(strict_types=1);

use App\Enums\Dosham;
use App\Enums\EmployerType;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The wizard-step tables (PRD §7.1, M02). One row per profile (profile_id is the key), deleted
| with the profile. Columns follow the M02 step table; the v4 DDL they were defined in is not
| available, so this shape is the build's proposal (docs/decisions.md). Multi-select partner
| preferences are JSON id lists (PRD §7.2 v5 notes) — the matcher reads them as a whole.
*/
return new class extends Migration
{
    public function up(): void
    {
        // Step 2 — education & career.
        Schema::create('education_careers', function (Blueprint $table): void {
            $table->foreignUlid('profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('education_id')->nullable()->constrained('master_education')->restrictOnDelete();
            $table->string('education_detail', 150)->nullable();     // degree / institute
            $table->foreignId('occupation_id')->nullable()->constrained('master_occupations')->restrictOnDelete();
            $table->string('employer_type', 20)->nullable();
            $table->string('employer_name', 150)->nullable();
            $table->foreignId('income_band_id')->nullable()->constrained('master_income_bands')->restrictOnDelete();
            $table->string('citizenship', 80)->nullable();          // NRI
            $table->string('visa_status', 80)->nullable();          // NRI
            $table->foreignId('current_country_id')->nullable()->constrained('master_countries')->restrictOnDelete();
            $table->foreignId('current_state_id')->nullable()->constrained('master_states')->restrictOnDelete();
            $table->foreignId('current_district_id')->nullable()->constrained('master_districts')->restrictOnDelete();
            $table->string('current_city', 80)->nullable();
            $table->foreignId('permanent_country_id')->nullable()->constrained('master_countries')->restrictOnDelete();
            $table->foreignId('permanent_state_id')->nullable()->constrained('master_states')->restrictOnDelete();
            $table->foreignId('permanent_district_id')->nullable()->constrained('master_districts')->restrictOnDelete();
            $table->string('permanent_city', 80)->nullable();
            $table->timestamps();

            $table->index(['education_id']);
            $table->index(['occupation_id']);
            $table->index(['current_country_id']);
        });
        EnumCheck::add('education_careers', 'employer_type', EmployerType::class, nullable: true);

        // Step 3 — family.
        Schema::create('family_details', function (Blueprint $table): void {
            $table->foreignUlid('profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('father_name', 100)->nullable();
            $table->string('father_occupation', 100)->nullable();
            $table->string('mother_name', 100)->nullable();
            $table->string('mother_occupation', 100)->nullable();
            $table->unsignedTinyInteger('brothers_married')->default(0);
            $table->unsignedTinyInteger('brothers_unmarried')->default(0);
            $table->unsignedTinyInteger('sisters_married')->default(0);
            $table->unsignedTinyInteger('sisters_unmarried')->default(0);
            $table->foreignId('family_status_option_id')->nullable()->constrained('master_options')->restrictOnDelete();
            $table->foreignId('family_type_option_id')->nullable()->constrained('master_options')->restrictOnDelete();
            $table->foreignId('family_values_option_id')->nullable()->constrained('master_options')->restrictOnDelete();
            $table->string('native_place', 100)->nullable();
            $table->string('about_family', 1000)->nullable();
            $table->timestamps();
        });

        // Step 4 — partner preferences. Empty list = "any".
        Schema::create('partner_preferences', function (Blueprint $table): void {
            $table->foreignUlid('profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->unsignedSmallInteger('height_min_cm')->nullable();
            $table->unsignedSmallInteger('height_max_cm')->nullable();
            $table->json('marital_statuses')->nullable();         // list<MaritalStatus value>
            $table->json('physical_statuses')->nullable();        // list<PhysicalStatus value>
            $table->json('religion_ids')->nullable();
            $table->json('caste_ids')->nullable();
            $table->json('mother_tongue_ids')->nullable();
            $table->json('star_ids')->nullable();
            $table->json('education_ids')->nullable();
            $table->json('occupation_ids')->nullable();
            $table->foreignId('min_income_band_id')->nullable()->constrained('master_income_bands')->restrictOnDelete();
            $table->json('country_ids')->nullable();
            $table->json('district_ids')->nullable();
            $table->json('diet_option_ids')->nullable();
            $table->string('about_partner', 1000)->nullable();
            $table->timestamps();
        });

        // Step 5 — contact. The primary mobile is users.phone (verified); these are extras and
        // are only ever shown under the phone-visibility rules (M03/M11).
        Schema::create('contact_details', function (Blueprint $table): void {
            $table->foreignUlid('profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('alternate_phone', 16)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_person', 100)->nullable();
            $table->string('contact_relation', 40)->nullable();
            $table->string('convenient_time', 80)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->foreignId('country_id')->nullable()->constrained('master_countries')->restrictOnDelete();
            $table->foreignId('state_id')->nullable()->constrained('master_states')->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('master_districts')->restrictOnDelete();
            $table->string('city', 80)->nullable();
            $table->timestamps();
        });

        // Step 1 horoscope extras (star / rasi live on profiles for search). The horoscope file
        // itself is a private media item (M11, P1.4).
        Schema::create('horoscope_details', function (Blueprint $table): void {
            $table->foreignUlid('profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->time('birth_time')->nullable();
            $table->string('birth_place', 100)->nullable();
            $table->string('chovva_dosham', 20)->nullable();
            $table->string('papa_dosham', 20)->nullable();
            $table->timestamps();
        });
        EnumCheck::add('horoscope_details', 'chovva_dosham', Dosham::class, nullable: true);
        EnumCheck::add('horoscope_details', 'papa_dosham', Dosham::class, nullable: true);

        // Step 6 lifestyle (v5 addition, PRD §7.2).
        Schema::create('lifestyle_details', function (Blueprint $table): void {
            $table->foreignUlid('profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('diet_option_id')->nullable()->constrained('master_options')->restrictOnDelete();
            $table->foreignId('smoking_option_id')->nullable()->constrained('master_options')->restrictOnDelete();
            $table->foreignId('drinking_option_id')->nullable()->constrained('master_options')->restrictOnDelete();
            $table->foreignId('complexion_option_id')->nullable()->constrained('master_options')->restrictOnDelete();
            $table->foreignId('body_type_option_id')->nullable()->constrained('master_options')->restrictOnDelete();
            $table->json('hobbies')->nullable();                   // list<string>
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifestyle_details');
        Schema::dropIfExists('horoscope_details');
        Schema::dropIfExists('contact_details');
        Schema::dropIfExists('partner_preferences');
        Schema::dropIfExists('family_details');
        Schema::dropIfExists('education_careers');
    }
};
