<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safe to re-run: a previously interrupted enable may have left the table behind.
        if (Schema::hasTable('ext_drift_register_signups')) {
            return;
        }

        Schema::create('ext_drift_register_signups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            // How the account was made: password, email (verification link), google or discord.
            $table->string('method', 16);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_drift_register_signups');
    }
};
