<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Tests\TestCase;

class TenantDomainsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['domains.base' => 'example.test']);
        Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'status' => 'active', 'database_status' => 'ready', 'database_config' => [], 'auth_config' => ['local' => ['enabled' => true]]]);
    }

    public function test_public_catalog_contains_only_ready_active_tenants_and_public_fields(): void
    {
        Tenant::create(['slug' => 'pending', 'name' => 'Pending', 'status' => 'active', 'database_status' => 'pending', 'auth_config' => []]);
        Tenant::create(['slug' => 'disabled', 'name' => 'Disabled', 'status' => 'suspended', 'database_status' => 'ready', 'auth_config' => []]);
        $this->getJson('https://example.test/api/public/tenants')->assertOk()->assertExactJson([
            'data' => [['slug' => 'alpha', 'name' => 'Alpha', 'login_url' => 'https://alpha.example.test']], 'has_more' => false,
        ])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('https://alpha.example.test/api/public/tenants')->assertNotFound();
    }

    public function test_hostname_is_authoritative_and_unknown_hosts_do_not_fall_back(): void
    {
        $this->getJson('https://alpha.example.test/auth/providers')->assertOk();
        $this->withHeader('X-Tenant', 'beta')->getJson('https://alpha.example.test/auth/providers')->assertStatus(422);
        $this->withHeader('X-Tenant', 'alpha')->getJson('https://example.test/auth/providers')->assertNotFound();
        $this->getJson('https://alpha.evil.test/auth/providers')->assertNotFound();
        $this->getJson('https://nested.alpha.example.test/auth/providers')->assertNotFound();
    }

    public function test_certificate_allowlist_requires_an_active_provisioned_tenant(): void
    {
        $this->get('/internal/tls-allowed?domain=alpha.example.test')->assertOk();
        foreach (['unknown.example.test', 'example.test', 'alpha.evil.test', 'nested.alpha.example.test'] as $domain) {
            $this->get('/internal/tls-allowed?domain='.$domain)->assertForbidden();
        }
    }

    public function test_csrf_bootstrap_returns_a_session_token_without_caching(): void
    {
        $this->getJson('https://alpha.example.test/auth/csrf-token')->assertOk()->assertJsonStructure(['token'])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_only_exact_landing_origin_receives_credentialed_cors(): void
    {
        config(['cors.allowed_origins' => ['https://example.test']]);
        $headers = ['Origin' => 'https://example.test', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'X-CSRF-TOKEN,X-Tenant,Content-Type'];
        $this->options('https://alpha.example.test/auth/login', [], $headers)
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://example.test')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->options('https://alpha.example.test/auth/login', [], array_replace($headers, ['Origin' => 'https://evil.example']))
            ->assertHeader('Access-Control-Allow-Origin', 'https://example.test');
    }
}
