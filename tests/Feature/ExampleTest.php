<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_identifies_the_api(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertExactJson([
            'service' => 'rline-api',
            'status' => 'ok',
        ]);
    }

    public function test_migrations_run_on_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('jobs'));
    }
}
