<?php

namespace Tests\Feature;

use App\Auth\MultifactorOidc;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultifactorOidcTest extends TestCase
{
    private string $privateKey = '';

    private array $keys;

    private array $metadata;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $base64url = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $this->keys = ['keys' => [['kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'n' => $base64url($rsa['n']), 'e' => $base64url($rsa['e'])]]];
        $this->metadata = ['issuer' => 'https://oidc.example.test', 'authorization_endpoint' => 'https://oidc.example.test/authorize', 'token_endpoint' => 'https://oidc.example.test/token', 'jwks_uri' => 'https://oidc.example.test/keys'];
        config(['authentication.providers.multifactor.enabled' => true,
            'authentication.providers.multifactor.issuer' => $this->metadata['issuer'],
            'authentication.providers.multifactor.client_id' => 'rline-client',
            'authentication.providers.multifactor.client_secret' => 'test-secret',
            'authentication.providers.multifactor.discovery_url' => 'https://oidc.example.test/discovery']);
        Http::preventStrayRequests();
    }

    private function mockToken(array $claims = []): void
    {
        $token = JWT::encode(array_replace(['iss' => $this->metadata['issuer'], 'aud' => 'rline-client', 'sub' => 'stable-subject', 'iat' => time(), 'exp' => time() + 300, 'nonce' => 'correct-nonce'], $claims), $this->privateKey, 'RS256', 'test-key');
        Http::fake([
            'https://oidc.example.test/discovery' => Http::response($this->metadata),
            'https://oidc.example.test/token' => Http::response(['id_token' => $token]),
            'https://oidc.example.test/keys' => Http::response($this->keys),
        ]);
    }

    public function test_signed_identity_and_authorization_request_binding(): void
    {
        $this->mockToken();
        $oidc = app(MultifactorOidc::class);
        $metadata = $oidc->configuration();
        $this->assertSame('stable-subject', $oidc->identity($metadata, 'authorization-code', 'correct-nonce', 'pkce-verifier'));
        parse_str(parse_url($oidc->authorizationUrl($metadata, 'state', 'nonce', 'verifier'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('state', $query['state']);
        $this->assertSame('nonce', $query['nonce']);
        Http::assertSent(fn ($request) => $request->url() === 'https://oidc.example.test/token' && $request['code_verifier'] === 'pkce-verifier');
    }

    public function test_wrong_nonce_is_rejected(): void
    {
        $this->mockToken(['nonce' => 'forged']);
        $this->expectException(\RuntimeException::class);
        app(MultifactorOidc::class)->identity($this->metadata, 'code', 'correct-nonce', 'verifier');
    }

    public function test_wrong_audience_is_rejected(): void
    {
        $this->mockToken(['aud' => 'another-client']);
        $this->expectException(\RuntimeException::class);
        app(MultifactorOidc::class)->identity($this->metadata, 'code', 'correct-nonce', 'verifier');
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->mockToken(['iat' => time() - 1000, 'exp' => time() - 300]);
        $this->expectException(ExpiredException::class);
        app(MultifactorOidc::class)->identity($this->metadata, 'code', 'correct-nonce', 'verifier');
    }

    public function test_discovery_issuer_is_pinned(): void
    {
        $this->mockToken();
        config(['authentication.providers.multifactor.issuer' => 'https://different.example.test']);
        $this->expectException(\RuntimeException::class);
        app(MultifactorOidc::class)->configuration();
    }

    public function test_callback_state_cannot_be_replayed_or_create_an_account(): void
    {
        $this->mockToken();
        $this->withSession(['oidc' => ['state' => 'valid-state', 'nonce' => 'correct-nonce', 'verifier' => 'verifier', 'expires' => time() + 300]])
            ->getJson('/auth/multifactor/callback?state=valid-state&code=code')->assertForbidden();
        $this->assertDatabaseCount('users', 0);
        $this->getJson('/auth/multifactor/callback?state=valid-state&code=code')->assertForbidden();
    }
}
