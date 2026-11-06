<?php

namespace App\Auth\Providers;

use App\Auth\Contracts\CredentialProvider;
use App\Auth\DirectoryClient;
use App\Models\User;
use App\Tenancy\TenantContext;

class DirectoryProvider implements CredentialProvider
{
    public function __construct(private string $id, private DirectoryClient $client) {}

    public function authenticate(string $username, string $password): ?User
    {
        $context = app(TenantContext::class);
        $subject = $this->client->verify($context->provider($this->id), $username, $password);

        return $subject ? User::where('is_active', true)->whereExists(fn ($q) => $q->selectRaw('1')->from('user_tenants')
            ->whereColumn('user_id', 'users.id')->where('tenant_id', $context->id())->where('user_tenants.is_active', true)
            ->where('user_tenants.auth_provider', $this->id)->where('user_tenants.auth_subject', $subject))->first() : null;
    }
}
