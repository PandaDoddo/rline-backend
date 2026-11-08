<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantAuthSettings;
use App\Tenancy\TenantDatabaseManager;
use App\Tenancy\TenantSchemaMigrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

Artisan::command('tenant:migrate {--tenant=}', function () {
    $query = Tenant::where('database_status', 'ready');
    if ($this->option('tenant')) {
        $query->where('slug', $this->option('tenant'));
    }
    if (! $query->exists()) {
        $this->error('Нет подготовленных БД для миграции.');

        return 1;
    }
    $failed = false;
    $query->chunkById(50, function ($tenants) use (&$failed) {
        foreach ($tenants as $tenant) {
            try {
                app(TenantSchemaMigrator::class)->migrate($tenant);
                $this->info('Миграции применены: '.$tenant->slug);
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
                $this->error('Не удалось обновить: '.$tenant->slug.'; подробности в журнале сервера.');
            }
        }
    });

    return $failed ? 1 : 0;
})->purpose('Применить предметные миграции к существующим tenant-БД');

Artisan::command('tenant:create {slug} {name} {--owner=}', function () {
    $data = ['slug' => $this->argument('slug'), 'name' => $this->argument('name')];
    Validator::make($data, ['slug' => 'required|regex:/^[a-z0-9][a-z0-9-]{0,62}$/D|unique:tenants,slug', 'name' => 'required|string|max:255'])->validate();
    $owner = User::where('email', $this->option('owner'))->firstOrFail();
    $tenant = DB::transaction(function () use ($data, $owner) {
        $tenant = Tenant::create($data + ['auth_config' => ['local' => ['enabled' => true]]]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => 'owner', 'auth_provider' => 'local']);

        return $tenant;
    });
    app(TenantDatabaseManager::class)->provision($tenant);
    $this->info('Тенант создан, отдельная БД готова: '.$tenant->slug);
});

Artisan::command('tenant:provision {slug}', function () {
    app(TenantDatabaseManager::class)->provision(Tenant::where('slug', $this->argument('slug'))->firstOrFail());
    $this->info('База тенанта готова.');
});

Artisan::command('tenant:platform-admin {email}', function () {
    User::where('email', $this->argument('email'))->firstOrFail()->forceFill(['is_platform_admin' => true])->save();
    $this->info('Назначен администратор платформы.');
});

Artisan::command('tenant:user-add {slug} {email} {--role=member} {--provider=local} {--subject=}', function () {
    $tenant = Tenant::where('slug', $this->argument('slug'))->firstOrFail();
    $user = User::where('email', $this->argument('email'))->firstOrFail();
    $data = ['role' => $this->option('role'), 'auth_provider' => $this->option('provider'), 'auth_subject' => $this->option('subject')];
    Validator::make($data, ['role' => 'required|in:owner,admin,member', 'auth_provider' => 'required|in:local,ldap,ad,multifactor', 'auth_subject' => ($data['auth_provider'] === 'local' ? 'nullable' : 'required').'|string|max:255'])->validate();
    TenantMembership::updateOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id], $data + ['is_active' => true]);
    DB::table('sessions')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
    $this->info('Членство сохранено; прежние сеансы в этом тенанте отозваны.');
});

Artisan::command('tenant:auth-config {slug} {file}', function () {
    $tenant = Tenant::where('slug', $this->argument('slug'))->firstOrFail();
    $config = json_decode(file_get_contents($this->argument('file')), true, flags: JSON_THROW_ON_ERROR);
    app(TenantAuthSettings::class)->update($tenant, $config);
    $this->info('Настройки провайдеров сохранены. Секреты зашифрованы.');
});
