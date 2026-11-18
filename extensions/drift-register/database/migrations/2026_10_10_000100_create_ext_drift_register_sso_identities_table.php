<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safe to re-run: a previously interrupted enable may have left the table behind.
        if (Schema::hasTable('ext_drift_register_sso_identities')) {
            return;
        }

        Schema::create('ext_drift_register_sso_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('email', 191)->nullable();
            $table->timestamps();

            // One external account can belong to one Panel user, and a Panel user can
            // link each provider once.
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_drift_register_sso_identities');
    }
};
