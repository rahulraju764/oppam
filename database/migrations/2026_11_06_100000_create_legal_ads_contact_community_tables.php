<?php

declare(strict_types=1);

use App\Enums\AdAudience;
use App\Enums\AdSlot;
use App\Enums\ContactStatus;
use App\Enums\ContentStatus;
use App\Enums\LegalKind;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A08 / M12 (P8.1b). Legal pages are versioned: one row per version, a published version is never
| edited (the next one is published instead) and acceptances point at the exact version a member
| agreed to. Ad creatives, community landing pages carry the same editorial workflow as P8.1a's
| tables. Contact submissions are an inbox (assign, reply, convert to ticket, spam), not content.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $this->workflow($table);
            $table->string('kind', 20);
            $table->unsignedSmallInteger('version');
            $table->string('title', 120);
            $table->string('summary', 500)->nullable();
            $table->longText('body');
            $table->date('effective_on');
            $table->boolean('is_material')->default(false);
            $table->boolean('legally_reviewed')->default(false);
            $table->unique(['kind', 'version']);
            $table->index(['kind', 'status', 'version']);
        });

        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('legal_document_id')->constrained('legal_documents')->restrictOnDelete();
            $table->string('kind', 20);
            $table->unsignedSmallInteger('version');
            $table->string('context', 20);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('accepted_at')->useCurrent();   // explicit default: see docs/decisions.md (MariaDB implicit ON UPDATE)
            $table->unique(['user_id', 'legal_document_id']);
            $table->index(['user_id', 'kind', 'version']);
        });

        Schema::create('ad_creatives', function (Blueprint $table): void {
            $this->workflow($table);
            $table->string('slot', 20);
            $table->string('audience', 20);
            $table->unsignedTinyInteger('weight')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('heading', 80);
            $table->string('copy', 200)->nullable();
            $table->string('cta_label', 40)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->string('alt', 200)->nullable();
            $table->string('image_path', 255)->nullable();
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
            $table->index(['status', 'slot', 'audience']);
        });

        Schema::create('community_pages', function (Blueprint $table): void {
            $this->workflow($table);
            $table->string('slug', 120)->unique();
            $table->string('heading', 120);
            $table->text('intro');
            $table->string('meta_description', 200);
            $table->foreignId('religion_id')->nullable()->constrained('master_religions')->restrictOnDelete();
            $table->foreignId('caste_id')->nullable()->constrained('master_castes')->restrictOnDelete();
        });

        Schema::create('contact_submissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 20)->unique();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('email', 191);
            $table->string('category', 30);
            $table->text('message');
            $table->string('status', 20)->default(ContactStatus::New->value);
            $table->string('spam_reason', 100)->nullable();
            $table->foreignUlid('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignUlid('support_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();
            $table->char('ip_hash', 64)->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['ip_hash', 'created_at']);   // SubmitContact's same-text-from-same-IP spam check
            $table->index('user_id');
        });

        Schema::create('contact_replies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('contact_submission_id')->constrained('contact_submissions')->cascadeOnDelete();
            $table->foreignUlid('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();   // explicit default: see docs/decisions.md (MariaDB implicit ON UPDATE)
        });

        foreach (['legal_documents', 'ad_creatives', 'community_pages'] as $name) {
            EnumCheck::add($name, 'status', ContentStatus::class);
        }
        EnumCheck::add('legal_documents', 'kind', LegalKind::class);
        EnumCheck::add('ad_creatives', 'slot', AdSlot::class);
        EnumCheck::add('ad_creatives', 'audience', AdAudience::class);
        EnumCheck::add('contact_submissions', 'status', ContactStatus::class);
    }

    public function down(): void
    {
        foreach (['contact_replies', 'contact_submissions', 'community_pages', 'ad_creatives', 'legal_acceptances', 'legal_documents'] as $name) {
            Schema::dropIfExists($name);
        }
    }

    /** The A08 workflow columns, as in 2026_11_02_100000_create_cms_content_tables. */
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
};
