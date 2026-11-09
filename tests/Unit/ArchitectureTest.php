<?php

namespace Tests\Unit;

use App\Modules\Equipment\Domain\PassportSchema;
use App\Modules\Installations\Domain\InstallationPassportSchema;
use App\Modules\ProcessFlows\Domain\FlowEnvironmentSchema;
use App\Modules\ProcessFlows\Domain\FlowPropertySchema;
use App\Modules\ProcessFlows\Domain\FlowScreening;
use Tests\TestCase;

class ArchitectureTest extends TestCase
{
    public function test_installation_passport_fields_match_the_versioned_relational_schema(): void
    {
        $snapshot = json_decode(file_get_contents(resource_path('schema/installation-passport-v1.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
        $this->assertSame(array_keys(InstallationPassportSchema::FIELDS), array_keys($snapshot));
        foreach (InstallationPassportSchema::FIELDS as $key => [$column, $type, $max]) {
            $this->assertSame(['column' => $column, 'type' => $type, 'max' => $max, 'options' => InstallationPassportSchema::OPTIONS[$key] ?? []], $snapshot[$key]);
        }
    }

    public function test_environment_fields_have_fixed_columns_or_a_canonical_alias(): void
    {
        $schema = app(FlowScreening::class)->schema();
        $columns = FlowEnvironmentSchema::COLUMNS;
        $aliases = FlowEnvironmentSchema::ALIASES;
        $expected = array_column($schema['fields'], 'id');
        $actual = [...array_keys($columns), ...array_keys($aliases)];
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        foreach ($schema['fields'] as $field) {
            if (isset($columns[$field['id']])) {
                $this->assertSame($field['type'] === 'boolean' ? 'boolean' : 'double', $columns[$field['id']][1]);
            }
        }
        $this->assertSame(['process_flow_properties', 'ph', 'double'], $aliases['ph']);
    }

    public function test_flow_properties_have_a_declared_relational_type(): void
    {
        $fields = config('process-flows.fields');
        $types = FlowPropertySchema::TYPES;
        $this->assertSame(array_keys($fields), array_keys($types));
        foreach ($fields as $key => $field) {
            $this->assertSame($field['type'] === 'number' ? 'double' : 'text', $types[$key], $key);
        }
    }

    public function test_business_domain_does_not_import_database_models_or_http_controllers(): void
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && str_contains(str_replace('\\', '/', $file->getPathname()), '/Domain/')) {
                $files[] = $file->getPathname();
            }
        }
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/use\s+App\\\\(?:Modules\\\\[^;]+\\\\Models|Http\\\\Controllers)\\\\/', $source, $file);
            $this->assertStringNotContainsString('Illuminate\\Support\\Facades\\DB', $source, $file);
        }
    }

    public function test_every_declared_passport_field_has_a_versioned_relational_type(): void
    {
        $schema = PassportSchema::fields();
        $fields = config('equipment.passport');
        $this->assertSame(array_keys($fields), array_keys($schema), 'New passport fields require an explicit relational migration.');
        foreach ($fields as $key => $field) {
            $expected = ($field['type'] ?? '') === 'date' ? 'date' : (($field['type'] ?? '') === 'number' ? (str_contains($field['rules'], 'integer') ? 'integer' : 'double') : 'text');
            $this->assertSame($expected, $schema[$key]['type'], $key);
            $this->assertSame($field['options'] ?? [], $schema[$key]['options'], $key);
        }
    }
}
