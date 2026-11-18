<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'ext_drift_register_sso_identities';

    public function up(): void
    {
        // What the admin user page shows for a linked Google / Discord account. Each column is
        // checked on its own, so this is safe to re-run after a partial failure.
        foreach ([
            'display_name' => fn (Blueprint $table) => $table->string('display_name', 191)->nullable(),
            'avatar_url' => fn (Blueprint $table) => $table->string('avatar_url', 255)->nullable(),
            'last_login_at' => fn (Blueprint $table) => $table->timestamp('last_login_at')->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $definition($table));
            }
        }
    }

    public function down(): void
    {
        foreach (['display_name', 'avatar_url', 'last_login_at'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
