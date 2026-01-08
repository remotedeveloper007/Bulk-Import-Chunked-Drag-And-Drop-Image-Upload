<?php

namespace Tests\Unit;

use App\Actions\ImportProductsAction;
use App\Models\Product;
use PHPUnit\Framework\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class ImportProductsActionTest extends BaseTestCase
{
    use RefreshDatabase;

    protected ImportProductsAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new ImportProductsAction();
    }

    /**
     * Test basic upsert: create new products
     */
    public function test_imports_new_products_successfully(): void
    {
        $csv = $this->createCsvFile([
            ['sku', 'name', 'price'],
            ['SKU001', 'Product A', '19.99'],
            ['SKU002', 'Product B', '29.99'],
        ]);

        $result = $this->action->execute($csv);

        $this->assertEquals(2, $result['total_rows']);
        $this->assertEquals(2, $result['imported_count']);
        $this->assertEquals(0, $result['updated_count']);
        $this->assertEquals(0, $result['invalid_count']);
        $this->assertEquals(0, $result['duplicate_count']);

        $this->assertDatabaseHas('products', ['sku' => 'SKU001', 'name' => 'Product A']);
        $this->assertDatabaseHas('products', ['sku' => 'SKU002', 'name' => 'Product B']);
    }

    /**
     * Test upsert: update existing products by SKU
     */
    public function test_updates_existing_products_by_sku(): void
    {
        // Create existing product
        Product::create([
            'sku' => 'SKU001',
            'name' => 'Old Name',
            'price' => 10.00,
        ]);

        $csv = $this->createCsvFile([
            ['sku', 'name', 'price'],
            ['SKU001', 'Updated Name', '29.99'],
            ['SKU002', 'New Product', '15.00'],
        ]);

        $result = $this->action->execute($csv);

        $this->assertEquals(2, $result['total_rows']);
        $this->assertEquals(1, $result['imported_count']); // Only SKU002 is new
        $this->assertEquals(1, $result['updated_count']); // SKU001 was updated
        $this->assertEquals(0, $result['invalid_count']);
        $this->assertEquals(0, $result['duplicate_count']);

        // Verify the update happened
        $product = Product::where('sku', 'SKU001')->first();
        $this->assertEquals('Updated Name', $product->name);
        $this->assertEquals(29.99, $product->price);
    }

    /**
     * Test detection of duplicates within CSV
     */
    public function test_detects_duplicates_within_csv(): void
    {
        $csv = $this->createCsvFile([
            ['sku', 'name', 'price'],
            ['SKU001', 'Product A', '19.99'],
            ['SKU001', 'Product A Duplicate', '25.00'], // Duplicate SKU
            ['SKU002', 'Product B', '29.99'],
        ]);

        $result = $this->action->execute($csv);

        $this->assertEquals(3, $result['total_rows']);
        $this->assertEquals(2, $result['imported_count']); // Only SKU001 and SKU002
        $this->assertEquals(0, $result['updated_count']);
        $this->assertEquals(0, $result['invalid_count']);
        $this->assertEquals(1, $result['duplicate_count']); // One duplicate detected

        // Only first occurrence should be in DB
        $this->assertDatabaseHas('products', ['sku' => 'SKU001', 'name' => 'Product A']);
        $this->assertDatabaseMissing('products', ['sku' => 'SKU001', 'name' => 'Product A Duplicate']);
    }

    /**
     * Test marking rows as invalid when required columns are missing
     */
    public function test_marks_rows_invalid_when_missing_columns(): void
    {
        $csv = $this->createCsvFile([
            ['sku', 'name', 'price'],
            ['SKU001', 'Product A', '19.99'],
            ['', 'Product B', '29.99'], // Missing SKU
            ['SKU003', '', '39.99'],     // Missing name
            ['SKU004', 'Product D', ''], // Missing price
            ['SKU005', 'Product E', '49.99'],
        ]);

        $result = $this->action->execute($csv);

        $this->assertEquals(5, $result['total_rows']);
        $this->assertEquals(2, $result['imported_count']); // SKU001, SKU005
        $this->assertEquals(0, $result['updated_count']);
        $this->assertEquals(3, $result['invalid_count']); // Three rows invalid
        $this->assertEquals(0, $result['duplicate_count']);

        $this->assertDatabaseCount('products', 2);
    }

    /**
     * Test large CSV with 10,000+ rows
     */
    public function test_handles_large_csv_efficiently(): void
    {
        $rows = [['sku', 'name', 'price']];
        for ($i = 1; $i <= 10000; $i++) {
            $rows[] = ["SKU{$i}", "Product {$i}", mt_rand(10, 100) . '.' . mt_rand(0, 99)];
        }

        $csv = $this->createCsvFile($rows);

        $result = $this->action->execute($csv);

        $this->assertEquals(10000, $result['total_rows']);
        $this->assertEquals(10000, $result['imported_count']);
        $this->assertEquals(0, $result['updated_count']);
        $this->assertEquals(0, $result['invalid_count']);
        $this->assertEquals(0, $result['duplicate_count']);

        $this->assertDatabaseCount('products', 10000);
    }

    /**
     * Test concurrent import safety (idempotent upsert)
     */
    public function test_upsert_is_idempotent(): void
    {
        $csv = $this->createCsvFile([
            ['sku', 'name', 'price'],
            ['SKU001', 'Product A', '19.99'],
            ['SKU002', 'Product B', '29.99'],
        ]);

        // First import
        $result1 = $this->action->execute($csv);
        $this->assertEquals(2, $result1['imported_count']);

        // Second import of same data (idempotent)
        $result2 = $this->action->execute($csv);
        $this->assertEquals(0, $result2['imported_count']); // No new imports
        $this->assertEquals(2, $result2['updated_count']); // Both updated (even if no change)

        // Database should still have 2 products
        $this->assertDatabaseCount('products', 2);
    }

    /**
     * Test price validation
     */
    public function test_rejects_invalid_prices(): void
    {
        $csv = $this->createCsvFile([
            ['sku', 'name', 'price'],
            ['SKU001', 'Product A', '19.99'],
            ['SKU002', 'Product B', 'invalid-price'], // Invalid price
            ['SKU003', 'Product C', '-5.00'],         // Negative price
            ['SKU004', 'Product D', '29.99'],
        ]);

        $result = $this->action->execute($csv);

        $this->assertEquals(4, $result['total_rows']);
        $this->assertEquals(2, $result['imported_count']);
        $this->assertEquals(2, $result['invalid_count']);

        $this->assertDatabaseCount('products', 2);
    }

    /**
     * Test empty CSV (only header)
     */
    public function test_handles_empty_csv(): void
    {
        $csv = $this->createCsvFile([
            ['sku', 'name', 'price'],
        ]);

        $result = $this->action->execute($csv);

        $this->assertEquals(0, $result['total_rows']);
        $this->assertEquals(0, $result['imported_count']);
        $this->assertDatabaseCount('products', 0);
    }

    /**
     * Test whitespace trimming
     */
    public function test_trims_whitespace_from_values(): void
    {
        $csv = $this->createCsvFile([
            ['sku', 'name', 'price'],
            [' SKU001 ', ' Product A ', ' 19.99 '],
            ['SKU002', 'Product B', '29.99'],
        ]);

        $result = $this->action->execute($csv);

        $this->assertEquals(2, $result['imported_count']);

        // Verify whitespace was trimmed
        $product = Product::where('sku', 'SKU001')->first();
        $this->assertEquals('SKU001', $product->sku);
        $this->assertEquals('Product A', $product->name);
    }

    /**
     * Test mixed scenario: create, update, invalid, duplicate
     */
    public function test_mixed_scenario_create_update_invalid_duplicate(): void
    {
        // Setup: one existing product
        Product::create(['sku' => 'EXISTING', 'name' => 'Existing Product', 'price' => 10.00]);

        $csv = $this->createCsvFile([
            ['sku', 'name', 'price'],
            ['EXISTING', 'Updated Existing', '15.00'],     // Update
            ['NEW001', 'New Product 1', '19.99'],           // Create
            ['NEW002', 'New Product 2', ''],                // Invalid
            ['NEW003', 'New Product 3', '29.99'],           // Create
            ['NEW003', 'Duplicate New 3', '35.00'],         // Duplicate
        ]);

        $result = $this->action->execute($csv);

        $this->assertEquals(5, $result['total_rows']);
        $this->assertEquals(2, $result['imported_count']);  // NEW001, NEW003
        $this->assertEquals(1, $result['updated_count']);   // EXISTING
        $this->assertEquals(1, $result['invalid_count']);   // NEW002
        $this->assertEquals(1, $result['duplicate_count']); // NEW003 duplicate

        $this->assertDatabaseCount('products', 3); // EXISTING, NEW001, NEW003
    }

    /**
     * Helper to create a CSV file from array of rows
     */
    protected function createCsvFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_');
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }
}
