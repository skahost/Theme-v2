<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safe to re-run: a previously interrupted enable may have left the table behind.
        if (Schema::hasTable('ext_drift_register_pending_registrations')) {
            return;
        }

        Schema::create('ext_drift_register_pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('username', 191);
            $table->string('name_first', 191);
            $table->string('name_last', 191);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_drift_register_pending_registrations');
    }
};
