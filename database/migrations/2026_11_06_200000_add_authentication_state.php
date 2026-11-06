<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_provider', 32)->default('local');
            $table->string('auth_subject')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('password_changed_at')->nullable();
            $table->unique(['auth_provider', 'auth_subject']);
        });
        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('password');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 64);
            $table->string('provider', 32)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
        Schema::dropIfExists('password_histories');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['auth_provider', 'auth_subject']);
            $table->dropColumn(['auth_provider', 'auth_subject', 'is_active', 'password_changed_at']);
        });
    }
};
