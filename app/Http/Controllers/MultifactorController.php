<?php

namespace App\Http\Controllers;

use App\Auth\AuthAudit;
use App\Auth\MultifactorOidc;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class MultifactorController extends Controller
{
    public function redirect(Request $request, MultifactorOidc $oidc)
    {
        $metadata = $oidc->configuration();
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $request->session()->regenerate();
        $request->session()->put('oidc', compact('state', 'nonce', 'verifier') + ['expires' => time() + 300, 'tenant_id' => app(TenantContext::class)->id()]);

        return redirect()->away($oidc->authorizationUrl($metadata, $state, $nonce, $verifier));
    }

    public function callback(Request $request, MultifactorOidc $oidc)
    {
        $pending = $request->session()->pull('oidc');
        if (! $pending || ($pending['tenant_id'] ?? null) !== app(TenantContext::class)->id() || $pending['expires'] < time() || ! is_string($request->input('state'))
            || ! hash_equals($pending['state'], $request->input('state')) || ! is_string($request->input('code'))) {
            abort(403, 'Подтверждение входа недействительно.');
        }
        try {
            $subject = $oidc->identity($oidc->configuration(), $request->input('code'), $pending['nonce'], $pending['verifier']);
        } catch (Throwable) {
            AuthAudit::record('oidc_failed', null, 'multifactor');
            abort(403, 'Не удалось проверить ответ Multifactor.');
        }
        $user = User::where('is_active', true)->whereExists(fn ($q) => $q->selectRaw('1')->from('user_tenants')->whereColumn('user_id', 'users.id')
            ->where('tenant_id', app(TenantContext::class)->id())->where('user_tenants.is_active', true)
            ->where('user_tenants.auth_provider', 'multifactor')->where('user_tenants.auth_subject', $subject))->first();
        abort_unless($user, 403, 'Учётная запись не предоставлена администратором.');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->away(config('authentication.frontend_url'));
    }
}
