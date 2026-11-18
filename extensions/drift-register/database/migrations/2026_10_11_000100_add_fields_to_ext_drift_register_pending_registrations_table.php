<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'ext_drift_register_pending_registrations';

    public function up(): void
    {
        // Each column is checked on its own, so this is safe to re-run after a partial failure.
        foreach ([
            'company' => fn (Blueprint $table) => $table->string('company', 191)->nullable(),
            'phone' => fn (Blueprint $table) => $table->string('phone', 32)->nullable(),
            // Google / Discord accounts connected on the form, linked once the email is verified.
            'sso' => fn (Blueprint $table) => $table->text('sso')->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $definition($table));
            }
        }
    }

    public function down(): void
    {
        foreach (['company', 'phone', 'sso'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
