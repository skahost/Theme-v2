<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'ext_drift_register_sso_identities';

    public function up(): void
    {
        // The provider's own username (a Discord username), so admins can see who an account is.
        // Safe to re-run after a partial failure.
        if (! Schema::hasColumn(self::TABLE, 'username')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->string('username', 191)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn(self::TABLE, 'username')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn('username'));
        }
    }
};
