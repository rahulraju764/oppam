<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A08 content (P8.1a, PRD M12): every table carries the editorial workflow. `draft` holds edits to a
| live row until they are published, so the site never shows unpublished text. Images live on the
| public disk (ContentImageStore) or are bundled assets, with their dimensions for width/height.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('success_stories', function (Blueprint $table): void {
            $this->workflow($table);
            $table->string('slug', 140)->unique();
            $table->string('couple', 120);
            $table->string('place', 80);
            $table->date('wedding_date');
            $table->string('quote', 300);
            $table->text('body');
            $this->image($table);
            $table->timestamp('consent_a_at')->nullable();
            $table->timestamp('consent_b_at')->nullable();
            $table->string('consent_note', 500)->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('withdrawn_reason', 500)->nullable();
            $table->foreignUlid('profile_a_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->foreignUlid('profile_b_id')->nullable()->constrained('profiles')->nullOnDelete();
        });

        Schema::create('branches', function (Blueprint $table): void {
            $this->workflow($table);
            $table->string('city', 80);
            $table->string('role', 40);
            $table->string('address', 300);
            $table->string('phone', 40);
            $table->string('tel', 16);
            $table->string('hours', 120);
            $table->string('map_url', 500)->nullable();
        });

        Schema::create('faq_items', function (Blueprint $table): void {
            $this->workflow($table);
            $table->string('question', 300);
            $table->text('answer');
        });

        Schema::create('testimonials', function (Blueprint $table): void {
            $this->workflow($table);
            $table->string('name', 80);
            $table->string('place', 80);
            $table->string('quote', 500);
            $this->image($table);
            $table->timestamp('consent_at')->nullable();
        });

        Schema::create('banners', function (Blueprint $table): void {
            $this->workflow($table);
            $table->string('heading', 120);
            $table->string('copy', 300);
            $table->string('alt', 200)->nullable();
            $table->string('cta_label', 40)->nullable();
            $table->string('cta_url', 500)->nullable();
            $this->image($table);
        });

        Schema::create('home_blocks', function (Blueprint $table): void {
            $this->workflow($table);
            $table->string('block_key', 30)->unique();
            $table->json('content');
        });

        foreach (['success_stories', 'branches', 'faq_items', 'testimonials', 'banners', 'home_blocks'] as $name) {
            EnumCheck::add($name, 'status', ContentStatus::class);
        }

        Schema::table('privacy_settings', function (Blueprint $table): void {
            $table->boolean('show_on_home')->default(false)->after('contact_filter_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('privacy_settings', function (Blueprint $table): void {
            $table->dropColumn('show_on_home');
        });

        foreach (['home_blocks', 'banners', 'testimonials', 'faq_items', 'branches', 'success_stories'] as $name) {
            Schema::dropIfExists($name);
        }
    }

    private function workflow(Blueprint $table): void
    {
        $table->ulid('id')->primary();
        $table->string('code', 20)->unique();
        $table->string('status', 20)->default(ContentStatus::Draft->value);
        $table->unsignedSmallInteger('sort_order')->default(0);
        $table->timestamp('scheduled_at')->nullable();
        $table->timestamp('published_at')->nullable();
        $table->timestamp('archived_at')->nullable();
        $table->json('draft')->nullable();
        $table->unsignedInteger('lock_version')->default(0);
        $table->foreignUlid('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
        $table->foreignUlid('published_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
        $table->timestamps();
        $table->index(['status', 'sort_order']);
        $table->index(['status', 'scheduled_at']);
    }

    private function image(Blueprint $table): void
    {
        $table->string('image_path', 255)->nullable();
        $table->unsignedSmallInteger('image_width')->nullable();
        $table->unsignedSmallInteger('image_height')->nullable();
    }
};
