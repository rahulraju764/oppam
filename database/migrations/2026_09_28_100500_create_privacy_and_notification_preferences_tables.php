<?php

declare(strict_types=1);

use App\Enums\HoroscopeVisibility;
use App\Enums\PhoneVisibility;
use App\Enums\PhotoVisibility;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* privacy_settings and notification_preferences exactly as PRD §7.2. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_settings', function (Blueprint $table): void {
            $table->foreignUlid('profile_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('photo_visibility', 20)->default(PhotoVisibility::AllMembers->value);
            $table->string('phone_visibility', 20)->default(PhoneVisibility::PremiumOnly->value);
            $table->string('horoscope_visibility', 20)->default(HoroscopeVisibility::AllMembers->value);
            $table->boolean('show_online_status')->default(true);
            $table->boolean('show_last_seen')->default(true);
            $table->boolean('read_receipts')->default(true);
            $table->boolean('incognito')->default(false);               // hidden from search; visible to existing connections
            $table->boolean('contact_filter_enabled')->default(false);  // only partner-pref matches may contact me
            $table->timestamps();
        });

        EnumCheck::add('privacy_settings', 'photo_visibility', PhotoVisibility::class);
        EnumCheck::add('privacy_settings', 'phone_visibility', PhoneVisibility::class);
        EnumCheck::add('privacy_settings', 'horoscope_visibility', HoroscopeVisibility::class);

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);                // interest_received, new_message, … (P3.2)
            $table->boolean('in_app')->default(true);   // always true for safety events
            $table->boolean('email')->default(true);
            $table->boolean('sms')->default(false);
            $table->boolean('push')->default(false);    // v1.5
            $table->timestamps();
            $table->unique(['user_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('privacy_settings');
    }
};
