<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test CSV import endpoint with valid file
     */
    public function test_csv_import_endpoint_returns_success(): void
    {
        $csv = UploadedFile::fake()->createWithContent(
            'products.csv',
            $this->csvContent([
                ['sku', 'name', 'price'],
                ['SKU001', 'Product A', '19.99'],
                ['SKU002', 'Product B', '29.99'],
            ])
        );

        $response = $this->postJson('/import/products', ['csv' => $csv]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'total_rows',
                'imported_count',
                'updated_count',
                'invalid_count',
                'duplicate_count',
            ],
        ]);

        $this->assertDatabaseCount('products', 2);
    }

    /**
     * Test endpoint with missing CSV file
     */
    public function test_import_endpoint_requires_csv_file(): void
    {
        $response = $this->postJson('/import/products', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('csv');
    }

    /**
     * Test endpoint with invalid file type
     */
    public function test_import_endpoint_validates_file_type(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson('/import/products', ['csv' => $file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('csv');
    }

    /**
     * Test endpoint validates CSV structure (headers)
     */
    public function test_import_endpoint_validates_csv_headers(): void
    {
        $csv = UploadedFile::fake()->createWithContent(
            'products.csv',
            // Wrong headers
            "wrong,headers,here\nSKU001,Product A,19.99\n"
        );

        $response = $this->postJson('/import/products', ['csv' => $csv]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('Missing required columns', json_encode($response->json()));
    }

    /**
     * Test large CSV import (performance test)
     */
    public function test_import_large_csv_10000_rows(): void
    {
        $rows = [['sku', 'name', 'price']];
        for ($i = 1; $i <= 10000; $i++) {
            $rows[] = ["SKU{$i}", "Product {$i}", (string)(10 + ($i % 100))];
        }

        $csv = UploadedFile::fake()->createWithContent(
            'large-products.csv',
            $this->csvContent($rows)
        );

        $response = $this->postJson('/import/products', ['csv' => $csv]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_rows', 10000);
        $response->assertJsonPath('data.imported_count', 10000);

        $this->assertDatabaseCount('products', 10000);
    }

    /**
     * Test import with mix of valid and invalid rows
     */
    public function test_import_with_mixed_valid_invalid_rows(): void
    {
        $csv = UploadedFile::fake()->createWithContent(
            'products.csv',
            $this->csvContent([
                ['sku', 'name', 'price'],
                ['SKU001', 'Valid Product', '19.99'],
                ['', 'Missing SKU', '29.99'],
                ['SKU003', '', '39.99'],
                ['SKU004', 'Invalid Price', 'not-a-number'],
                ['SKU005', 'Valid Product 2', '49.99'],
            ])
        );

        $response = $this->postJson('/import/products', ['csv' => $csv]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_rows', 5);
        $response->assertJsonPath('data.imported_count', 2);
        $response->assertJsonPath('data.invalid_count', 3);

        $this->assertDatabaseCount('products', 2);
    }

    /**
     * Test import handles update correctly
     */
    public function test_import_updates_existing_products(): void
    {
        // Create existing product
        Product::create(['sku' => 'SKU001', 'name' => 'Old Name', 'price' => 10.00]);

        $csv = UploadedFile::fake()->createWithContent(
            'products.csv',
            $this->csvContent([
                ['sku', 'name', 'price'],
                ['SKU001', 'Updated Name', '20.00'],
                ['SKU002', 'New Product', '30.00'],
            ])
        );

        $response = $this->postJson('/import/products', ['csv' => $csv]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.imported_count', 1);
        $response->assertJsonPath('data.updated_count', 1);

        $product = Product::where('sku', 'SKU001')->first();
        $this->assertEquals('Updated Name', $product->name);
        $this->assertEquals(20.00, $product->price);
    }

    /**
     * Test duplicate detection within CSV
     */
    public function test_import_detects_duplicates_in_csv(): void
    {
        $csv = UploadedFile::fake()->createWithContent(
            'products.csv',
            $this->csvContent([
                ['sku', 'name', 'price'],
                ['SKU001', 'Product A', '19.99'],
                ['SKU001', 'Duplicate A', '25.00'],
                ['SKU002', 'Product B', '29.99'],
            ])
        );

        $response = $this->postJson('/import/products', ['csv' => $csv]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_rows', 3);
        $response->assertJsonPath('data.imported_count', 2);
        $response->assertJsonPath('data.duplicate_count', 1);

        // Only first occurrence should be in database
        $count = Product::where('sku', 'SKU001')->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Test idempotent import (same CSV twice)
     */
    public function test_import_is_idempotent(): void
    {
        $csv = UploadedFile::fake()->createWithContent(
            'products.csv',
            $this->csvContent([
                ['sku', 'name', 'price'],
                ['SKU001', 'Product A', '19.99'],
                ['SKU002', 'Product B', '29.99'],
            ])
        );

        // First import
        $response1 = $this->postJson('/import/products', ['csv' => $csv]);
        $response1->assertJsonPath('data.imported_count', 2);

        // Create new file instance (Laravel will re-read)
        $csv2 = UploadedFile::fake()->createWithContent(
            'products.csv',
            $this->csvContent([
                ['sku', 'name', 'price'],
                ['SKU001', 'Product A', '19.99'],
                ['SKU002', 'Product B', '29.99'],
            ])
        );

        // Second import (same data)
        $response2 = $this->postJson('/import/products', ['csv' => $csv2]);
        $response2->assertJsonPath('data.imported_count', 0); // No new imports
        $response2->assertJsonPath('data.updated_count', 2);  // Both updated

        // Database should still have only 2 products
        $this->assertDatabaseCount('products', 2);
    }

    /**
     * Test import with empty CSV (header only)
     */
    public function test_import_handles_empty_csv(): void
    {
        $csv = UploadedFile::fake()->createWithContent(
            'products.csv',
            "sku,name,price\n"
        );

        $response = $this->postJson('/import/products', ['csv' => $csv]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.total_rows', 0);
        $response->assertJsonPath('data.imported_count', 0);

        $this->assertDatabaseCount('products', 0);
    }

    /**
     * Test response includes error details
     */
    public function test_import_response_includes_error_details(): void
    {
        $csv = UploadedFile::fake()->createWithContent(
            'products.csv',
            $this->csvContent([
                ['sku', 'name', 'price'],
                ['SKU001', 'Valid', '19.99'],
                ['', 'Invalid', '29.99'],
            ])
        );

        $response = $this->postJson('/import/products', ['csv' => $csv]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.invalid_count', 1);
        $response->assertJsonStructure(['data' => ['errors']]);
    }

    /**
     * Test CSV with whitespace in values
     */
    public function test_import_trims_whitespace(): void
    {
        $csv = UploadedFile::fake()->createWithContent(
            'products.csv',
            $this->csvContent([
                ['sku', 'name', 'price'],
                [' SKU001 ', ' Product A ', ' 19.99 '],
            ])
        );

        $response = $this->postJson('/import/products', ['csv' => $csv]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.imported_count', 1);

        $product = Product::first();
        $this->assertEquals('SKU001', $product->sku);
        $this->assertEquals('Product A', $product->name);
        $this->assertEquals(19.99, $product->price);
    }

    /**
     * Helper: Convert array of rows to CSV content
     */
    private function csvContent(array $rows): string
    {
        $output = '';
        foreach ($rows as $row) {
            $output .= implode(',', $row) . "\n";
        }
        return $output;
    }
}
