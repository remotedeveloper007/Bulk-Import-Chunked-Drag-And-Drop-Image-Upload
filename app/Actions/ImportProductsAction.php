<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\Upload;
use App\Models\Image;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * ImportProductsAction
 *
 * Handles bulk CSV import of products with upsert logic and optional image linking.
 * 
 * Features:
 * - Memory-efficient chunked processing (LazyCollection)
 * - Transaction-safe database operations
 * - Concurrency-safe upserts using database-level unique constraints
 * - Duplicate detection within CSV (prevents duplicate key conflicts)
 * - Invalid row tracking (missing required columns)
 * - Accurate import/update count differentiation
 * - Automatic image linking when CSV contains image column (Task requirement: "Images linked from CSV")
 *
 * CSV Formats Supported:
 * 1. Basic (backward compatible): sku, name, price
 * 2. With images: sku, name, price, image
 *
 * Image Linking (Per Task Requirements):
 * - Matches by original_name in uploads table
 * - Case-insensitive matching
 * - Extension-flexible (e.g., "product" matches "product.jpg", "product.png")
 * - Only links completed uploads (status='completed')
 * - If image not found, logs warning but continues import (non-breaking)
 * - Idempotent: re-attaching same image = no-op
 */
final class ImportProductsAction
{
    private const CHUNK_SIZE = 1000;
    private const REQUIRED_COLUMNS = ['sku', 'name', 'price'];

    /**
     * Execute the import operation
     *
     * @param string $path Path to the CSV file
     * @return array Summary with total_rows, imported_count, updated_count, invalid_count, duplicate_count, images_linked, images_not_found
     */
    public function execute(string $path): array
    {
        $seenSkus = [];
        $summary = [
            'total_rows' => 0,
            'imported_count' => 0,
            'updated_count' => 0,
            'invalid_count' => 0,
            'duplicate_count' => 0,
            'images_linked' => 0,
            'images_not_found' => 0,
            'errors' => [],
        ];

        // Check if CSV has image column (backward compatible)
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        fclose($handle);
        
        $headerLower = array_map('strtolower', array_map('trim', $header));
        $hasImageColumn = in_array('image', $headerLower);

        try {
            LazyCollection::make(function () use ($path) {
                $handle = fopen($path, 'r');
                if (!$handle) {
                    return;
                }

                // Skip header
                fgetcsv($handle);

                // Yield each line
                while (($line = fgets($handle)) !== false) {
                    yield $line;
                }

                fclose($handle);
            })
                ->chunk(self::CHUNK_SIZE)
                ->each(function ($rows) use (&$summary, &$seenSkus, $hasImageColumn) {
                    $this->processChunk($rows, $summary, $seenSkus, $hasImageColumn);
                });
        } catch (\Throwable $e) {
            $summary['errors'][] = 'Fatal error: ' . $e->getMessage();
        }

        return $summary;
    }

    /**
     * Process a chunk of CSV rows
     *
     * @param iterable $rows
     * @param array $summary
     * @param array $seenSkus
     * @param bool $hasImageColumn
     */
    private function processChunk(iterable $rows, array &$summary, array &$seenSkus, bool $hasImageColumn): void
    {
        $payload = [];
        $imageLinks = []; // [sku => image_filename]

        foreach ($rows as $rowIndex => $row) {
            $summary['total_rows']++;

            // Parse CSV row
            $data = str_getcsv($row);
            
            // Validate required columns exist
            $validated = $this->validateRow($data, $rowIndex);
            
            if (!$validated['valid']) {
                $summary['invalid_count']++;
                $summary['errors'][] = $validated['error'];
                continue;
            }

            $sku = trim($validated['sku']);
            $name = trim($validated['name']);
            $price = trim($validated['price']);
            
            // Extract image filename if column exists (optional, non-breaking)
            $imageName = null;
            if ($hasImageColumn && isset($data[3])) {
                $imageName = trim($data[3]);
            }

            // Check for duplicates within CSV
            if (isset($seenSkus[$sku])) {
                $summary['duplicate_count']++;
                $summary['errors'][] = "Duplicate SKU in CSV: {$sku}";
                continue;
            }

            $seenSkus[$sku] = true;

            $payload[] = [
                'sku' => $sku,
                'name' => $name,
                'price' => (float) $price,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Store image link for post-upsert processing
            if ($imageName) {
                $imageLinks[$sku] = $imageName;
            }
        }

        // If no valid rows, skip database operation
        if (empty($payload)) {
            return;
        }

        // Perform upsert in transaction (concurrency-safe)
        DB::transaction(function () use ($payload, &$summary, $imageLinks) {
            $skus = array_column($payload, 'sku');
            
            // Count existing products before upsert
            $existingCount = Product::whereIn('sku', $skus)->count();

            // Atomic upsert (no race conditions due to unique constraint)
            Product::upsert(
                $payload,
                ['sku'], // Unique key
                ['name', 'price', 'updated_at'] // Columns to update
            );

            $newCount = count($payload) - $existingCount;
            $summary['updated_count'] += $existingCount;
            $summary['imported_count'] += $newCount;

            // Link images if CSV contains image column (Task requirement)
            if (!empty($imageLinks)) {
                $this->linkImages($imageLinks, $summary);
            }
        });
    }

    /**
     * Link uploaded images to products based on filename matching
     * Implements task requirement: "Images linked from CSV processed correctly into variants"
     * 
     * Optimized with batch queries instead of N+1 lookups.
     *
     * @param array $imageLinks [sku => image_filename]
     * @param array $summary
     */
    private function linkImages(array $imageLinks, array &$summary): void
    {
        // Batch load all uploads once instead of per-SKU (N+1 optimization)
        $uploads = Upload::where('status', 'completed')
            ->with('images')
            ->get()
            ->keyBy('original_name');

        foreach ($imageLinks as $sku => $imageName) {
            // Skip empty image names
            if (empty($imageName)) {
                continue;
            }

            // Find product
            $product = Product::where('sku', $sku)->first();
            
            if (!$product) {
                continue; // Should not happen as we just upserted
            }

            // Match upload (optimized: batch lookup instead of query per SKU)
            $upload = null;
            $baseFilename = pathinfo($imageName, PATHINFO_FILENAME);
            
            // Strategy 1: Exact match
            if (isset($uploads[$imageName])) {
                $upload = $uploads[$imageName];
            }
            
            // Strategy 2: Case-insensitive match
            if (!$upload) {
                foreach ($uploads as $originalName => $u) {
                    if (strtolower($originalName) === strtolower($imageName)) {
                        $upload = $u;
                        break;
                    }
                }
            }
            
            // Strategy 3: Base filename match (without extension)
            if (!$upload) {
                foreach ($uploads as $originalName => $u) {
                    $uploadBaseFilename = pathinfo($originalName, PATHINFO_FILENAME);
                    if (strtolower($uploadBaseFilename) === strtolower($baseFilename)) {
                        $upload = $u;
                        break;
                    }
                }
            }

            if (!$upload) {
                $summary['images_not_found']++;
                $summary['errors'][] = "Image not found for SKU '{$sku}': {$imageName} (upload not completed or doesn't exist)";
                continue;
            }

            // Get the largest image variant (best quality for primary image)
            $image = $upload->images->sortByDesc('width')->first();

            if (!$image) {
                $summary['images_not_found']++;
                $summary['errors'][] = "No image variants found for upload: {$imageName}";
                continue;
            }

            // Link image to product (idempotent - task requirement: "Re-attaching the same upload to the same entity = no-op")
            if ($product->primary_image_id !== $image->id) {
                $product->primary_image_id = $image->id;
                $product->save();
                $summary['images_linked']++;
            }
        }
    }

    /**
     * Validate a CSV row has all required columns and valid data
     *
     * @param array $data
     * @param int $rowIndex
     * @return array
     */
    private function validateRow(array $data, int $rowIndex): array
    {
        if (count($data) < count(self::REQUIRED_COLUMNS)) {
            return [
                'valid' => false,
                'error' => "Row {$rowIndex}: Missing required columns. Expected: " . implode(', ', self::REQUIRED_COLUMNS),
            ];
        }

        $sku = $data[0] ?? null;
        $name = $data[1] ?? null;
        $price = $data[2] ?? null;

        if (empty($sku) || empty($name) || empty($price)) {
            return [
                'valid' => false,
                'error' => "Row {$rowIndex}: Required fields (sku, name, price) cannot be empty",
            ];
        }

        if (!is_numeric($price) || $price < 0) {
            return [
                'valid' => false,
                'error' => "Row {$rowIndex}: Price must be a valid positive number",
            ];
        }

        return [
            'valid' => true,
            'sku' => $sku,
            'name' => $name,
            'price' => $price,
        ];
    }
}
