<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TenantDatabaseManager
{
    public function provision(Tenant $tenant): void
    {
        // CREATE DATABASE cannot run in a transaction. Persist progress for safe retries.
        $connection = DB::connection('pgsql');
        $connection->select('select pg_advisory_lock(?)', [910000000 + $tenant->id]);
        try {
            $tenant->refresh();
            $config = $tenant->database_config ?? [
                'database' => 'rline_t_'.$tenant->id, 'username' => 'rline_t_'.$tenant->id,
                'password' => Str::random(48),
            ];
            $tenant->forceFill(['database_config' => $config, 'database_status' => 'provisioning'])->save();
            $name = $config['database'];
            $role = $config['username'];
            if (! preg_match('/^rline_t_[0-9]+$/D', $name) || $role !== $name) {
                throw new \RuntimeException('Invalid tenant database identity.');
            }
            if (! $connection->selectOne('select 1 from pg_roles where rolname = ?', [$role])) {
                $connection->unprepared('CREATE ROLE "'.$role.'" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD '.$connection->getPdo()->quote($config['password']));
            }
            if (! $connection->selectOne('select 1 from pg_database where datname = ?', [$name])) {
                $connection->unprepared('CREATE DATABASE "'.$name.'" OWNER "'.$role.'"');
            }
            $connection->unprepared('REVOKE ALL ON DATABASE "'.$name.'" FROM PUBLIC');
            $tenant->forceFill(['database_status' => 'ready'])->save();
            app(TenantContext::class)->set($tenant);
            DB::connection('tenant')->statement('CREATE TABLE IF NOT EXISTS tenant_identity (tenant_id bigint PRIMARY KEY)');
            DB::connection('tenant')->table('tenant_identity')->insertOrIgnore(['tenant_id' => $tenant->id]);
            $identity = DB::connection('tenant')->table('tenant_identity')->sole();
            if ((int) $identity->tenant_id !== $tenant->id) {
                throw new \RuntimeException('Tenant database identity mismatch.');
            }
        } catch (Throwable $exception) {
            $tenant->forceFill(['database_status' => 'failed'])->save();
            throw new \RuntimeException('Tenant database provisioning failed for tenant '.$tenant->id.' (code '.$exception->getCode().').');
        } finally {
            app(TenantContext::class)->set(null);
            $connection->select('select pg_advisory_unlock(?)', [910000000 + $tenant->id]);
        }
    }
}
