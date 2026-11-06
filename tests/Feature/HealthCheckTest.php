<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_checks_the_database(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson([
            'status' => 'ok',
            'database' => 'connected',
        ]);
    }

    public function test_health_returns_503_when_the_database_is_unavailable(): void
    {
        $database = DB::getFacadeRoot();

        DB::shouldReceive('select')
            ->once()
            ->with('select 1')
            ->andThrow(new RuntimeException('Database connection failed'));

        try {
            $this->getJson('/api/health')->assertStatus(503)->assertExactJson([
                'status' => 'error',
                'database' => 'unavailable',
            ]);

            $this->get('/up')->assertOk();
        } finally {
            DB::swap($database);
        }
    }

    public function test_cors_allows_the_frontend_origin(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
