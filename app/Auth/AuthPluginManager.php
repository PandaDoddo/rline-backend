<?php

namespace App\Auth;

use App\Auth\Contracts\CredentialProvider;
use App\Auth\Providers\DirectoryProvider;
use App\Auth\Providers\LocalProvider;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class AuthPluginManager
{
    private array $providers = [];

    public function __construct(DirectoryClient $directory)
    {
        $this->register('local', new LocalProvider);
        $this->register('ldap', new DirectoryProvider('ldap', $directory));
        $this->register('ad', new DirectoryProvider('ad', $directory));
    }

    public function register(string $id, CredentialProvider $provider): void
    {
        $this->providers[$id] = $provider;
    }

    public function resolve(string $id): CredentialProvider
    {
        if (! (app(TenantContext::class)->provider($id)['enabled'] ?? false) || ! isset($this->providers[$id])) {
            throw ValidationException::withMessages(['provider' => 'Этот способ входа недоступен.']);
        }

        return $this->providers[$id];
    }

    public function listing(): array
    {
        return collect(config('authentication.providers'))->map(fn ($base, $id) => app(TenantContext::class)->provider($id))->map(fn ($config, $id) => [
            'id' => $id, 'label' => $config['label'], 'enabled' => (bool) $config['enabled'],
            'type' => isset($config['status']) ? 'unsupported' : ($id === 'multifactor' ? 'redirect' : 'credentials'),
            'status' => $config['status'] ?? ($config['enabled'] ? 'enabled' : 'disabled'),
        ])->values()->all();
    }
}
