<?php

namespace Tests\Unit;

use App\Modules\Equipment\Domain\ComponentCode;
use PHPUnit\Framework\TestCase;

class ComponentCodeTest extends TestCase
{
    public function test_every_original_component_code_is_preserved(): void
    {
        $reference = json_decode(file_get_contents(__DIR__.'/../Fixtures/component-code-reference.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2627, $reference['root_count']);
        foreach ($reference['codes'] as $code) {
            $this->assertSame($code, (new ComponentCode)->allocate($code, null, 1)['code']);
        }
    }

    public function test_source_namespaces_and_gaps_do_not_renumber_each_other(): void
    {
        $codes = new ComponentCode;
        $this->assertSame(['code' => 'K-03', 'next' => 4], $codes->allocate('K-03', null, 1));
        $this->assertSame(['code' => 'K-S1', 'next' => 4], $codes->allocate('K-S1', null, 4));
        $this->assertSame(['code' => 'K-C2', 'next' => 4], $codes->allocate('K-C2', null, 4));
        $this->assertSame(['code' => 'K-S1.S9', 'next' => 10], $codes->allocate('K-S1.S9', 'K-S1', 1));
        $this->assertSame(['code' => 'K-S1.S10', 'next' => 11], $codes->allocate(null,'K-S1',10));
    }
}
