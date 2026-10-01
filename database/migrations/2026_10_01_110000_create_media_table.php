<?php

declare(strict_types=1);

use App\Enums\PhotoStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| spatie/laravel-medialibrary's media table (M11), adapted: owners have ULID keys (ulidMorphs), and
| profile photos carry their moderation state, caption and perceptual hash as real columns so
| queues and "approved photos only" queries don't dig through JSON. Files live under the media
| uuid (ObscurePathGenerator) with HMAC-named conversions (KeyedFileNamer), never a guessable name.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->ulid('id')->primary();     // CLAUDE.md: ULID keys (the public handle is still the uuid)

            $table->ulidMorphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();

            // Oppam (M11): photo moderation (A04), caption, duplicate detection (pHash, 64-bit hex).
            $table->string('moderation_status', 20)->default(PhotoStatus::Pending->value);
            $table->string('rejection_reason', 255)->nullable();
            $table->string('caption', 100)->nullable();
            $table->char('phash', 16)->nullable()->index();

            $table->nullableTimestamps();

            $table->index(['model_type', 'model_id', 'collection_name', 'moderation_status'], 'media_owner_collection_status_index');
        });

        EnumCheck::add('media', 'moderation_status', PhotoStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
