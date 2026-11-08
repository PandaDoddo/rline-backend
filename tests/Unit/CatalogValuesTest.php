<?php

namespace Tests\Unit;

use App\Domain\CatalogValues;
use PHPUnit\Framework\TestCase;

class CatalogValuesTest extends TestCase
{
    public function test_numeric_representation_and_object_key_order_do_not_change_a_checked_value(): void
    {
        $this->assertTrue(CatalogValues::same(['value' => 0, 'unit' => 'МПа'], ['unit' => 'МПа', 'value' => 0.0]));
        $this->assertFalse(CatalogValues::same(null, 0));
        $this->assertFalse(CatalogValues::same(false, 0));
        $this->assertFalse(CatalogValues::same('0', 0));
        $this->assertFalse(CatalogValues::same([1, 2], [2, 1]));
        $this->assertFalse(CatalogValues::same(['value' => 0], ['value' => 0.1]));
    }
}
