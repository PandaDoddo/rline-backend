<?php

namespace App\Modules\Shared\Application;

use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Shared\Models\DomainEvent;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Tenant-local audit pagination with one central actor lookup per page. */
final class AuditEvents
{
    public function page(string $entity, string $id, ?int $limit = 200): LengthAwarePaginator
    {
        $query = DomainEvent::where('entity_type', $entity)->where('entity_id', $id)->orderByDesc('id');
        if ($limit !== null) {
            $query = DomainEvent::query()->fromSub($query->limit($limit), 'domain_events')->orderByDesc('id');
        }
        $page = $query->paginate(30);
        $actors = User::on('pgsql')->whereIn('id', $page->getCollection()->pluck('actor_id')->unique())
            ->whereIn('id', TenantMembership::on('pgsql')->where('tenant_id', app(TenantContext::class)->id())->select('user_id'))->pluck('name', 'id');
        foreach ($page->getCollection() as $event) {
            $event->actor_name = $actors->get($event->actor_id);
        }

        return $page;
    }
}
