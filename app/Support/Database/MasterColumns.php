<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * The common master-data row shape (PRD A11): immutable `code`, editable `label`, `label_ml`
 * for the Malayalam UI (v2), `sort_order`, `is_active` (deactivate, never delete, once used).
 * Master tables keep integer keys — profiles reference them with foreignId() (PRD §7.2).
 */
final class MasterColumns
{
    public static function define(Blueprint $table, bool $uniqueCode = true): void
    {
        $table->id();
        $table->string('code', 40);
        $table->string('label', 120);
        $table->string('label_ml', 120)->nullable();
        $table->unsignedSmallInteger('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();

        if ($uniqueCode) {
            $table->unique('code');
        }
    }
}
