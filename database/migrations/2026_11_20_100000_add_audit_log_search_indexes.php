<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A12 audit viewer (P9.2a): free-text search over the audit trail (PRD A12 "viewer with filters")
| and the subject-code filter.
|  - FULLTEXT (action, actor_label, subject_label, reason) — MySQL / MariaDB only (InnoDB FTS);
|    AuditLogQuery searches it in boolean mode with prefix terms.
|  - (subject_label, created_at): "everything about OPM12370 / BRK1042 / ORD100004".
| Adding an index is not an UPDATE: the append-only triggers are untouched.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['subject_label', 'created_at'], 'audit_logs_subject_label_created_at_index');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->fullText(['action', 'actor_label', 'subject_label', 'reason'], 'audit_logs_search_fulltext');
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->dropFullText('audit_logs_search_fulltext');
            });
        }

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_subject_label_created_at_index');
        });
    }
};
