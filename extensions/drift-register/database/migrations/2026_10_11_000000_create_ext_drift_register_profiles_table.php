<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safe to re-run: a previously interrupted enable may have left the table behind.
        if (Schema::hasTable('ext_drift_register_profiles')) {
            return;
        }

        Schema::create('ext_drift_register_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('company', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_drift_register_profiles');
    }
};
