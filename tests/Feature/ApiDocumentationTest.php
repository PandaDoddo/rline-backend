<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_component_connections_and_history_keep_read_metadata_out_of_writes(): void
    {
        $spec = $this->spec();
        $schemas = $spec['components']['schemas'];
        $read = $schemas['EquipmentComponentResource']['properties'];
        $input = $schemas['UpdateEquipmentRequest']['properties']['structure']['items']['properties'];
        $this->assertSame('uuid', $read['connections']['items']['properties']['targets']['items']['format']);
        $this->assertSame('boolean', $read['connections']['items']['properties']['complete']['type']);
        $this->assertSame('string', $read['connections']['items']['properties']['unresolved_targets']['items']['type']);
        $this->assertSame('string', $input['connections']['items']['properties']['unresolved_targets']['items']['type']);
        $this->assertSame('uuid', $read['assemblies']['items']['properties']['members']['items']['format']);
        $this->assertArrayNotHasKey('complete', $input['connections']['items']['properties']);
        $this->assertSame('integer', $read['history_count']['type']);
        $this->assertArrayNotHasKey('history', $read);
        $this->assertArrayNotHasKey('recorded_by', $input['history']['items']['properties']);
        $this->assertArrayNotHasKey('recorded_at', $input['history']['items']['properties']);
        $history = $schemas['ComponentHistoryResource']['properties'];
        $this->assertSame('date', $history['occurred_on']['format']);
        $this->assertSame(['string', 'null'], $history['occurred_on']['type']);
        $this->assertSame('date-time', $history['recorded_at']['format']);
        $this->assertArrayHasKey('/api/equipment/{equipment}/components/{component}/history', $spec['paths']);
    }

    public function test_operating_scopes_describe_independent_parameters_and_server_owned_checks(): void
    {
        $schemas = $this->spec()['components']['schemas'];
        $input = $schemas['UpdateEquipmentRequest']['properties']['operating_scopes']['items'];
        $read = $schemas['EquipmentResource']['properties']['operating_scopes']['items'];
        $this->assertSame(['tube', 'coil'], $input['properties']['kind']['enum']);
        $this->assertSame(['number', 'null'], $input['properties']['values']['properties']['pressure_operating_mpa']['type']);
        $this->assertSame('boolean', $input['properties']['verification']['properties']['pressure_operating_mpa']['type']);
        $this->assertArrayNotHasKey('checks', $input['properties']);
        $this->assertSame('integer', $read['properties']['checks']['properties']['pressure_operating_mpa']['properties']['by']['type']);
        $this->assertSame('boolean', $read['properties']['persisted']['type']);
    }

    public function test_management_questionnaire_contract_keeps_answer_union_and_typed_schema(): void
    {
        $spec = $this->spec();
        $schemas = $spec['components']['schemas'];
        $this->assertSame('array', $schemas['ManagementSchemaResource']['properties']['sections']['type']);
        $value = $schemas['UpdateManagementRequest']['properties']['answers']['items']['properties']['value'];
        $this->assertSame(['boolean', 'number', 'array', 'null'], array_column($value['anyOf'], 'type'));
        $this->assertSame('integer', $value['anyOf'][2]['items']['type']);
        $this->assertSame('array', $schemas['AuditEventResource']['properties']['changes']['type']);
        $this->assertSame('string', $schemas['AuditEventResource']['properties']['changes']['items']['properties']['label']['type']);
        $this->assertArrayHasKey('/api/installations/{installation}/management/events', $spec['paths']);
    }

    private function spec(): array
    {
        $this->app['env'] = 'local';

        return $this->getJson('/docs/api.json')->assertOk()->json();
    }

    public function test_every_api_auth_and_csrf_method_is_documented(): void
    {
        $spec = $this->spec();
        $this->assertStringStartsWith('3.1.', $spec['openapi']);
        $expected = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') && ! str_starts_with($route->uri(), 'auth/') && $route->uri() !== 'sanctum/csrf-cookie') {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $expected[] = strtolower($method).' /'.$route->uri();
            }
        }
        $actual = [];
        $ids = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $actual[] = $method.' '.$path;
                $ids[] = $operation['operationId'];
            }
        }
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertCount(count($ids), array_unique($ids));
    }

    public function test_equipment_transactions_keep_resource_statuses_and_nested_contracts(): void
    {
        $spec = $this->spec();
        foreach ([['/api/equipment', 'post', 201], ['/api/equipment/{equipment}', 'get', 200], ['/api/equipment/{equipment}', 'patch', 200]] as [$path, $method, $status]) {
            $schema = $spec['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema'];
            $this->assertSame('#/components/schemas/EquipmentResource', $schema['properties']['data']['$ref']);
        }
        $this->assertArrayNotHasKey(200, $spec['paths']['/api/equipment']['post']['responses']);
        $resource = $spec['components']['schemas']['EquipmentResource']['properties'];
        $this->assertSame('object', $resource['thickness']['type']);
        $this->assertSame('array', $resource['thickness']['properties']['stages']['type']);
        $this->assertSame('object', $resource['condition']['type']);
        $this->assertSame('array', $resource['condition']['properties']['cmls']['type']);
        $this->assertSame('array', $resource['condition']['properties']['anomalies']['type']);
        $this->assertArrayHasKey('periodicity', $resource['condition']['properties']);
        $request = $spec['components']['schemas']['UpdateEquipmentRequest']['properties'];
        $this->assertArrayHasKey('structure', $request);
        $this->assertArrayHasKey('inspections', $request);
        $this->assertArrayHasKey('thickness', $request);
        $this->assertArrayNotHasKey('profile_code', $request);
        $this->assertArrayNotHasKey('checks', $request);
        $schedule = $spec['components']['schemas']['DiagnosticScheduleResource']['properties'];
        $this->assertSame('array', $schedule['diagnostics']['type']);
        $this->assertSame('date', $schedule['diagnostics']['items']['properties']['planned_on']['format']);
        $this->assertArrayNotHasKey('thickness', $schedule);
        $this->assertArrayNotHasKey('condition', $schedule);
    }

    public function test_cookie_security_tenant_and_csrf_are_described(): void
    {
        $spec = $this->spec();
        $scheme = $spec['components']['securitySchemes']['sessionCookie'];
        $this->assertSame('cookie', $scheme['in']);
        $this->assertSame(config('session.cookie'), $scheme['name']);
        $this->assertEmpty($spec['paths']['/auth/login']['post']['security']);
        $this->assertNotEmpty($spec['paths']['/auth/me']['get']['security'] ?? $spec['security']);
        $this->assertContains('X-Tenant', array_column($spec['paths']['/auth/login']['post']['parameters'], 'name'));
        $this->assertContains('X-XSRF-TOKEN', array_column($spec['paths']['/auth/login']['post']['parameters'], 'name'));
        $this->assertArrayHasKey(204, $spec['paths']['/auth/two-factor-challenge']['post']['responses']);
        $this->assertArrayNotHasKey(200, $spec['paths']['/auth/two-factor-challenge']['post']['responses']);
        $this->assertArrayHasKey(410, $spec['paths']['/auth/two-factor-challenge']['post']['responses']);
    }

    public function test_login_provider_and_nested_settings_follow_code_rules(): void
    {
        $spec = $this->spec();
        $login = $spec['components']['schemas']['LoginRequest']['properties'];
        $this->assertSame(['local', 'ldap', 'ad'], $login['provider']['enum']);
        $this->assertArrayNotHasKey('remember', $login);
        $paths = $spec['paths']['/api/tenants/{tenant}/authentication'];
        $this->assertArrayHasKey('put', $paths);
        $this->assertArrayNotHasKey('requestBody', $paths['get']);
        $this->assertNotContains('providers', array_column($paths['get']['parameters'], 'name'));
        $fields = $spec['components']['schemas']['UpdateTenantAuthenticationRequest']['properties']['providers']['properties'];
        $this->assertSame('boolean', $fields['ldap']['properties']['enabled']['type']);
        $this->assertSame(1024, $fields['ldap']['properties']['uri']['maxLength']);
        $this->assertSame(255, $fields['multifactor']['properties']['client_id']['maxLength']);
        $reset = $spec['paths']['/auth/reset-password']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertContains('password_confirmation', $reset['required']);
        $this->assertSame('string', $reset['properties']['token']['type']);
        $this->assertArrayHasKey('current_password', $spec['paths']['/auth/user/password']['put']['requestBody']['content']['application/json']['schema']['properties']);
    }

    public function test_generated_docs_do_not_run_tenant_operations_or_expose_model_secrets(): void
    {
        $tenants = DB::table('tenants')->count();
        $jobs = DB::table('jobs')->count();
        $spec = $this->spec();
        $this->assertSame($tenants, DB::table('tenants')->count());
        $this->assertSame($jobs, DB::table('jobs')->count());
        $tenant = $spec['components']['schemas']['Tenant']['properties'];
        $this->assertArrayNotHasKey('auth_config', $tenant);
        $this->assertArrayNotHasKey('database_config', $tenant);
        $this->assertStringNotContainsString(config('app.key'), json_encode($spec));
    }

    public function test_docs_are_local_only_by_default(): void
    {
        $this->app['env'] = 'production';
        $this->get('/docs/api')->assertForbidden();
        $this->getJson('/docs/api.json')->assertForbidden();
    }

    public function test_documentation_page_includes_cookie_aware_client(): void
    {
        $this->app['env'] = 'local';
        $this->get('/docs/api')->assertOk()->assertSee('R-Line API')->assertSee('elements-api', false)->assertSee('X-XSRF-TOKEN');
    }

    public function test_installation_contracts_are_typed_without_tenant_database_access(): void
    {
        DB::listen(function ($query) {
            $this->assertNotSame('tenant', $query->connectionName);
        });
        $spec = $this->spec();
        $schemas = $spec['components']['schemas'];
        $this->assertArrayNotHasKey('version', $schemas['InstallationRequest']['properties']);
        $this->assertContains('version', $schemas['UpdateInstallationRequest']['required']);
        $this->assertSame('integer', $schemas['UpdateInstallationRequest']['properties']['version']['type']);
        $this->assertArrayNotHasKey('kind', $schemas['UpdateFacilityNodeRequest']['properties']);
        $this->assertSame('object', $schemas['InstallationResource']['properties']['passport']['type']);
        $this->assertArrayHasKey('capP', $schemas['InstallationResource']['properties']['passport']['properties']);
        $this->assertSame(['Установки'], $spec['paths']['/api/installations']['get']['tags']);
    }
}
