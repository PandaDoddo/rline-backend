<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 63)->unique();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('auth_version')->default(1);
            $table->text('auth_config');
            $table->text('database_config')->nullable();
            $table->string('database_status', 16)->default('pending');
            $table->timestamps();
        });
        Schema::create('user_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->boolean('is_active')->default(true);
            $table->string('auth_provider', 32)->default('local');
            $table->string('auth_subject')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'auth_provider', 'auth_subject']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_platform_admin')->default(false));
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique(['auth_provider', 'auth_subject']));
        $tenant = DB::table('tenants')->insertGetId([
            'slug' => 'rline', 'name' => 'R-Line', 'status' => 'active',
            'auth_config' => Crypt::encryptString(json_encode(config('authentication.providers'))),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('users')->get() as $user) {
            DB::table('user_tenants')->insert([
                'tenant_id' => $tenant, 'user_id' => $user->id, 'role' => 'member',
                'auth_provider' => $user->auth_provider, 'auth_subject' => $user->auth_subject,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Schema::table('sessions', fn (Blueprint $table) => $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('sessions', fn (Blueprint $table) => $table->index(['tenant_id', 'user_id', 'last_activity']));
        Schema::table('auth_events', fn (Blueprint $table) => $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete());
        // Existing reset links predate a tenant binding; invalidate them.
        DB::table('password_reset_tokens')->delete();
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropPrimary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->primary(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        DB::table('password_reset_tokens')->delete();
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropConstrainedForeignId('tenant_id');
            $table->primary('email');
        });
        Schema::table('sessions', fn (Blueprint $table) => $table->dropConstrainedForeignId('tenant_id'));
        Schema::table('auth_events', fn (Blueprint $table) => $table->dropConstrainedForeignId('tenant_id'));
        Schema::dropIfExists('user_tenants');
        Schema::dropIfExists('tenants');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_admin'));
        Schema::table('users', fn (Blueprint $table) => $table->unique(['auth_provider', 'auth_subject']));
    }
};
