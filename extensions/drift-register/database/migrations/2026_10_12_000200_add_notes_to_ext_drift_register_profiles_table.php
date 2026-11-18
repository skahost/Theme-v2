<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'ext_drift_register_profiles';

    public function up(): void
    {
        // Private admin notes about a user. Safe to re-run after a partial failure.
        if (! Schema::hasColumn(self::TABLE, 'notes')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->text('notes')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn(self::TABLE, 'notes')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn('notes'));
        }
    }
};
