<?php

use App\Auth\AuthAudit;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

Artisan::command('auth:user-create {email} {--name=} {--provider=local} {--subject=} {--generate} {--tenant=rline}', function () {
    $tenant = Tenant::where('slug', $this->option('tenant'))->firstOrFail();
    $provider = $this->option('provider');
    if (! in_array($provider, ['local', 'ldap', 'ad', 'multifactor'], true) || ! filter_var($this->argument('email'), FILTER_VALIDATE_EMAIL)) {
        $this->error('Укажите корректные provider и email.');

        return 1;
    }
    if (User::where('email', mb_strtolower($this->argument('email')))->exists()) {
        $this->error('Учётная запись уже существует.');

        return 1;
    }
    if ($provider !== 'local' && ! $this->option('subject')) {
        $this->error('Для внешнего провайдера нужен стабильный --subject.');

        return 1;
    }
    $password = $provider !== 'local' || $this->option('generate') ? Str::password(24) : $this->secret('Пароль');
    Validator::make(['password' => $password], ['password' => ['required', Password::default()]])->validate();
    $user = new User;
    $user->forceFill(['email' => mb_strtolower($this->argument('email')), 'name' => $this->option('name') ?: $this->argument('email'),
        'auth_provider' => $provider, 'auth_subject' => $this->option('subject'), 'password' => $password,
        'password_changed_at' => now(), 'is_active' => true])->save();
    TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id,
        'auth_provider' => $provider, 'auth_subject' => $this->option('subject'), 'role' => 'member']);
    AuthAudit::record('user_provisioned', $user->id, $provider);
    $this->info('Учётная запись создана.');
    if ($provider === 'local' && $this->option('generate')) {
        $this->line('Email: '.$user->email);
        $this->line('Password: '.$password);
    }

    return 0;
})->purpose('Предоставить учётную запись выбранного провайдера');

require __DIR__.'/tenancy-console.php';
require __DIR__.'/data-exchange-console.php';

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
