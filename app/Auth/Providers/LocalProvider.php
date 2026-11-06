<?php

namespace App\Auth\Providers;

use App\Auth\Contracts\CredentialProvider;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

class LocalProvider implements CredentialProvider
{
    public function authenticate(string $username, string $password): ?User
    {
        $user = User::where('email', mb_strtolower($username))->where('is_active', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('user_tenants')->whereColumn('user_id', 'users.id')
                ->where('tenant_id', app(TenantContext::class)->id())->where('user_tenants.is_active', true)->where('user_tenants.auth_provider', 'local'))->first();
        // Always perform a hash check, including for an unknown identity.
        $valid = Hash::check($password, $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

        return $valid && $user?->is_active ? $user : null;
    }
}
