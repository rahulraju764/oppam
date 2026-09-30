<?php

declare(strict_types=1);

use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Enums\ProfileStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The matrimony profile (PRD §7.2). `code` (OPM10001) is public and used in URLs; the ULID never
| leaves the server. Broker columns (owner_type, managed_by_broker_id, broker_id, …) are added
| in P7.1 together with the brokers table they reference (docs/decisions.md).
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
            $table->string('last_name', 60);
            $table->date('dob');
            $table->unsignedSmallInteger('height_cm');
            $table->unsignedSmallInteger('weight_kg')->nullable();
            $table->string('marital_status', 20);
            $table->unsignedTinyInteger('children_count')->default(0);
            $table->string('physical_status', 30)->default(PhysicalStatus::Normal->value);
            $table->foreignId('religion_id')->constrained('master_religions')->restrictOnDelete();
            $table->foreignId('caste_id')->nullable()->constrained('master_castes')->restrictOnDelete();
            $table->boolean('caste_no_bar')->default(false);
            $table->string('sub_caste', 80)->nullable();
            $table->foreignId('mother_tongue_id')->constrained('master_mother_tongues')->restrictOnDelete();
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
        EnumCheck::add('profiles', 'marital_status', MaritalStatus::class);
        EnumCheck::add('profiles', 'physical_status', PhysicalStatus::class);
        EnumCheck::add('profiles', 'status', ProfileStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
