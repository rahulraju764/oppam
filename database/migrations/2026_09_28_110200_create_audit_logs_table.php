<?php

declare(strict_types=1);

use App\Enums\AuditActorType;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Append-only audit trail (PRD A12, CLAUDE.md security rule 8): every admin write, plus narrow
| read-logging (document views, break-glass conversation reads). Rows are never updated or
| deleted: the model refuses, and on MySQL/MariaDB triggers refuse at the database level too.
| Production additionally grants the app user INSERT/SELECT only on this table (P9.5). Retention
| 7 years. No FKs: the trail must outlive the rows it describes.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('actor_type', 10);
            $table->string('actor_id', 26)->nullable();
            $table->string('actor_label', 150)->nullable();       // e.g. the admin's email at the time
            $table->string('action', 80);                         // area.verb — admin.login, roles.updated
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 26)->nullable();
            $table->string('subject_label', 150)->nullable();     // code / email at the time
            $table->json('before')->nullable();                   // redacted
            $table->json('after')->nullable();                    // redacted
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at']);   // per-entity timeline
            $table->index(['actor_type', 'actor_id', 'created_at']);       // what did this admin do
            $table->index(['action', 'created_at']);
        });
        EnumCheck::add('audit_logs', 'actor_type', AuditActorType::class);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
            DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        }

        Schema::dropIfExists('audit_logs');
    }
};
