<?php

namespace Tests\Unit;

use App\Models\Product;
use PHPUnit\Framework\TestCase;

class ProductTest extends TestCase
{
    public function test_calibration_falls_back_to_the_defaults_field_by_field(): void
    {
        $product = new Product(['calibration' => ['forwardAxis' => '-x', 'scale' => '1.25']]);

        $calibration = $product->resolvedCalibration();

        $this->assertSame('-x', $calibration['forwardAxis']);
        $this->assertSame('+y', $calibration['upAxis'], 'glTF models are Y-up by default');
        $this->assertSame(1.25, $calibration['scale'], 'values from JSON are cast to numbers');
        $this->assertSame([0.0, 0.0, 0.0], $calibration['offsetCm']);
        $this->assertFalse($calibration['mirror']);
    }

    public function test_only_uploaded_files_are_treated_as_deletable(): void
    {
        $this->assertSame('products/models/abc.glb', Product::uploadedDiskPath('storage/products/models/abc.glb'));
        $this->assertNull(Product::uploadedDiskPath('models/samples/street-runner.glb'), 'bundled samples are not deleted');
        $this->assertNull(Product::uploadedDiskPath(null));
    }
}
