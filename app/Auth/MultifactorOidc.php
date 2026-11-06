<?php

namespace App\Auth;

use App\Tenancy\TenantContext;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MultifactorOidc
{
    public function configuration(): array
    {
        $config = app(TenantContext::class)->provider('multifactor');
        if (! $config['enabled'] || ! $config['client_id'] || ! $config['client_secret'] || ! $config['issuer']) {
            abort(503, 'Multifactor не настроен.');
        }
        $this->requireHttps($config['discovery_url']);
        $metadata = Http::timeout(10)->get($config['discovery_url'])->throw()->json();
        if (($metadata['issuer'] ?? null) !== $config['issuer']) {
            throw new RuntimeException('OIDC issuer mismatch.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            $this->requireHttps($metadata[$key] ?? '');
        }

        return $metadata;
    }

    public function authorizationUrl(array $metadata, string $state, string $nonce, string $verifier): string
    {
        return $metadata['authorization_endpoint'].'?'.http_build_query([
            'client_id' => app(TenantContext::class)->provider('multifactor')['client_id'],
            'redirect_uri' => app(TenantContext::class)->provider('multifactor')['callback'],
            'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function identity(array $metadata, string $code, string $nonce, string $verifier): string
    {
        $config = app(TenantContext::class)->provider('multifactor');
        $token = Http::asForm()->timeout(10)->post($metadata['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier,
            'redirect_uri' => $config['callback'], 'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'],
        ])->throw()->json();
        $keys = Http::timeout(10)->get($metadata['jwks_uri'])->throw()->json();
        $keys['keys'] = array_values(array_filter($keys['keys'] ?? [], fn ($key) => ($key['kty'] ?? null) === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256' && ($key['use'] ?? 'sig') === 'sig'));
        $claims = JWT::decode($token['id_token'] ?? '', JWK::parseKeySet($keys, 'RS256'));
        $audience = (array) ($claims->aud ?? []);
        if (($claims->iss ?? null) !== $config['issuer'] || ! in_array($config['client_id'], $audience, true)
            || (count($audience) > 1 && ($claims->azp ?? null) !== $config['client_id'])
            || ! isset($claims->exp, $claims->iat) || $claims->iat < time() - 600
            || ! hash_equals($nonce, $claims->nonce ?? '') || ! is_string($claims->sub ?? null) || $claims->sub === '') {
            throw new RuntimeException('OIDC claims invalid.');
        }

        return $claims->sub;
    }

    private function requireHttps(string $url): void
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! parse_url($url, PHP_URL_HOST)) {
            throw new RuntimeException('OIDC requires HTTPS endpoints.');
        }
    }
}
