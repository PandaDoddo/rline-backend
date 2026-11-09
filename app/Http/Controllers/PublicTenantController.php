<?php

namespace App\Http\Controllers;

use App\Tenancy\TenantDomains;
use Illuminate\Http\Request;

class PublicTenantController extends Controller
{
    public function index(Request $request, TenantDomains $domains)
    {
        abort_unless(config('domains.base') && $request->getHost() === config('domains.base'), 404);
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'search' => 'sometimes|string|max:100']);
        $query = $domains->available()->orderBy('name')->orderBy('slug');
        if ($search = $data['search'] ?? '') {
            $query->where(fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orWhere('slug', 'ilike', '%'.addcslashes($search, '%_\\').'%'));
        }
        $tenants = $query->simplePaginate(50, ['slug', 'name']);

        return response()->json([
            'data' => $tenants->map(fn ($tenant) => ['slug' => $tenant->slug, 'name' => $tenant->name, 'login_url' => $domains->url($tenant->slug)]),
            'has_more' => $tenants->hasMorePages(),
        ])->header('Cache-Control', 'no-store');
    }

    public function certificate(Request $request, TenantDomains $domains)
    {
        $domain = $request->query('domain');
        $slug = is_string($domain) ? $domains->slug(strtolower($domain)) : null;
        abort_unless($slug && $domains->available()->where('slug', $slug)->exists(), 403);

        return response('allowed', 200)->header('Cache-Control', 'no-store');
    }
}
