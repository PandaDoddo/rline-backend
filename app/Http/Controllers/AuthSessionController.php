<?php

namespace App\Http\Controllers;

use App\Auth\AuthAudit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthSessionController extends Controller
{
    public function current(Request $request)
    {
        $user = $request->user();

        return response()->json(['user' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'provider' => $user->auth_provider, 'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'two_factor_required' => app(TenantContext::class)->requiresTotp($user->auth_provider),
            'is_platform_admin' => $user->is_platform_admin,
            'tenant' => ['id' => (int) app(TenantContext::class)->id(), 'slug' => (string) app(TenantContext::class)->tenant->slug,
                'name' => (string) app(TenantContext::class)->tenant->name, 'role' => (string) app(TenantContext::class)->membership($user->id)->role],
        ]]);
    }

    public function index(Request $request)
    {
        return DB::table('sessions')->where('user_id', $request->user()->id)->orderByDesc('last_activity')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])->map(fn ($row) => [
                'id' => hash_hmac('sha256', $row->id, config('app.key')),
                'ip_address' => $row->ip_address === null ? null : (string) $row->ip_address,
                'user_agent' => $row->user_agent === null ? null : (string) $row->user_agent,
                'last_activity' => (int) $row->last_activity, 'current' => $row->id === $request->session()->getId(),
            ]);
    }

    public function destroy(Request $request, string $id)
    {
        $sessions = DB::table('sessions')->where('user_id', $request->user()->id)->where('tenant_id', app(TenantContext::class)->id())->get(['id']);
        foreach ($sessions as $session) {
            if ($session->id !== $request->session()->getId()
                && ($id === 'others' || hash_equals(hash_hmac('sha256', $session->id, config('app.key')), $id))) {
                DB::table('sessions')->where('id', $session->id)->delete();
            }
        }
        AuthAudit::record('sessions_revoked', $request->user()->id, $request->user()->auth_provider);

        return response()->noContent();
    }
}
