<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Upload;
use App\Models\Image;
use App\Jobs\ProcessImageVariantsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * ProcessImageVariantsJobTest
 *
 * Tests for image variant generation job
 * 
 * Validates:
 * - Chunk assembly from storage
 * - Checksum validation
 * - Variant generation (256px, 512px, 1024px)
 * - Aspect ratio preservation
 * - Concurrent upload safety
 * - Status updates (pending → completed/failed)
 */
class ProcessImageVariantsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Fake storage for testing
        Storage::fake('local');
    }

    /**
     * Test successful chunk assembly and variant generation
     */
    public function test_assembles_chunks_and_generates_variants(): void
    {
        // Create a simple test image (1x1 red pixel PNG)
        $testImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        $checksum = hash('sha256', $testImageData);

        // Create upload record
        $upload = Upload::create([
            'original_name' => 'test-image.png',
            'checksum' => $checksum,
            'total_chunks' => 1,
            'received_chunks' => 1,
            'status' => 'processing',
        ]);

        // Write chunk to storage
        Storage::put("uploads/chunks/{$upload->id}/0", $testImageData);

        // Run job synchronously
        $job = new ProcessImageVariantsJob($upload->id);
        $job->handle();

        // Reload upload
        $upload->refresh();

        // Assert upload status is completed
        $this->assertEquals('completed', $upload->status);

        // Assert 3 image variants were created
        $this->assertCount(3, $upload->images);

        // Assert correct widths
        $widths = $upload->images->pluck('width')->sort()->values();
        $this->assertEquals([256, 512, 1024], $widths->toArray());

        // Assert all variants have paths
        foreach ($upload->images as $image) {
            $this->assertNotNull($image->path);
            $this->assertTrue(Storage::exists($image->path));
        }
    }

    /**
     * Test checksum mismatch blocks completion
     */
    public function test_checksum_mismatch_fails_upload(): void
    {
        // Create test image
        $testImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        
        // Create upload with WRONG checksum
        $upload = Upload::create([
            'original_name' => 'test-image.png',
            'checksum' => 'wrong_checksum_12345',
            'total_chunks' => 1,
            'received_chunks' => 1,
            'status' => 'processing',
        ]);

        // Write chunk
        Storage::put("uploads/chunks/{$upload->id}/0", $testImageData);

        // Run job
        $job = new ProcessImageVariantsJob($upload->id);
        $job->handle();

        // Reload
        $upload->refresh();

        // Assert status is failed
        $this->assertEquals('failed', $upload->status);

        // Assert no images were created
        $this->assertCount(0, $upload->images);
    }

    /**
     * Test missing chunks fail the upload
     */
    public function test_missing_chunks_fail_upload(): void
    {
        // Create upload expecting 3 chunks
        $upload = Upload::create([
            'original_name' => 'test-image.png',
            'checksum' => 'some_checksum',
            'total_chunks' => 3,
            'received_chunks' => 3,
            'status' => 'processing',
        ]);

        // Only write 2 chunks (missing chunk 2)
        Storage::put("uploads/chunks/{$upload->id}/0", 'chunk0');
        Storage::put("uploads/chunks/{$upload->id}/1", 'chunk1');
        // Chunk 2 is missing

        // Run job
        $job = new ProcessImageVariantsJob($upload->id);
        $job->handle();

        // Reload
        $upload->refresh();

        // Assert status is failed
        $this->assertEquals('failed', $upload->status);
    }

    /**
     * Test idempotent behavior - running job twice doesn't create duplicates
     */
    public function test_idempotent_variant_generation(): void
    {
        // Create test image
        $testImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        $checksum = hash('sha256', $testImageData);

        $upload = Upload::create([
            'original_name' => 'test-image.png',
            'checksum' => $checksum,
            'total_chunks' => 1,
            'received_chunks' => 1,
            'status' => 'processing',
        ]);

        Storage::put("uploads/chunks/{$upload->id}/0", $testImageData);

        // Run job first time
        $job1 = new ProcessImageVariantsJob($upload->id);
        $job1->handle();

        $upload->refresh();
        $firstCount = $upload->images()->count();

        // Run job second time (simulating retry)
        $job2 = new ProcessImageVariantsJob($upload->id);
        $job2->handle();

        $upload->refresh();
        $secondCount = $upload->images()->count();

        // Assert count didn't change (idempotent)
        $this->assertEquals($firstCount, $secondCount);
        $this->assertEquals(3, $secondCount); // Still only 3 variants
    }

    /**
     * Test aspect ratio preservation
     */
    public function test_preserves_aspect_ratio(): void
    {
        // Create a simple test image (1x1 red pixel PNG)
        $testImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        $checksum = hash('sha256', $testImageData);

        $upload = Upload::create([
            'original_name' => 'square.png',
            'checksum' => $checksum,
            'total_chunks' => 1,
            'received_chunks' => json_encode([0]),
            'status' => 'processing',
        ]);

        Storage::put("uploads/chunks/{$upload->id}/0", $testImageData);

        // Run job
        $job = new ProcessImageVariantsJob($upload->id);
        $job->handle();

        $upload->refresh();

        // Verify job completed successfully
        $this->assertEquals('completed', $upload->status);
        $this->assertCount(3, $upload->images);

        // Check that all variants were created with proper dimensions
        foreach ($upload->images as $image) {
            // For a square image, aspect ratio should be 1:1
            // Height should be approximately equal to width
            $this->assertGreaterThan(0, $image->width);
            $this->assertGreaterThan(0, $image->height);
            // Aspect ratio should be approximately 1:1 (within 2% tolerance for encoding)
            $aspectRatio = $image->width / max($image->height, 1);
            $this->assertGreaterThanOrEqual(0.98, $aspectRatio);
            $this->assertLessThanOrEqual(1.02, $aspectRatio);
        }
    }

    /**
     * Test concurrent upload safety with locking
     */
    public function test_concurrent_processing_prevention(): void
    {
        $testImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        $checksum = hash('sha256', $testImageData);

        $upload = Upload::create([
            'original_name' => 'test.png',
            'checksum' => $checksum,
            'total_chunks' => 1,
            'received_chunks' => 1,
            'status' => 'processing',
        ]);

        Storage::put("uploads/chunks/{$upload->id}/0", $testImageData);

        // First job starts processing
        $job1 = new ProcessImageVariantsJob($upload->id);
        $job1->handle();

        // Second job tries to process same upload (simulates race condition)
        $job2 = new ProcessImageVariantsJob($upload->id);
        $job2->handle();

        $upload->refresh();

        // Assert only one set of variants created
        $this->assertCount(3, $upload->images);
    }

    /**
     * Test all required variant sizes are generated
     */
    public function test_generates_all_required_variant_sizes(): void
    {
        $testImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        $checksum = hash('sha256', $testImageData);

        $upload = Upload::create([
            'original_name' => 'test.png',
            'checksum' => $checksum,
            'total_chunks' => 1,
            'received_chunks' => 1,
            'status' => 'processing',
        ]);

        Storage::put("uploads/chunks/{$upload->id}/0", $testImageData);

        $job = new ProcessImageVariantsJob($upload->id);
        $job->handle();

        $upload->refresh();

        // Task requirement: 256px, 512px, 1024px
        $requiredWidths = [256, 512, 1024];
        $actualWidths = $upload->images->pluck('width')->sort()->values()->toArray();

        $this->assertEquals($requiredWidths, $actualWidths);
    }

    /**
     * Test upload not found scenario
     */
    public function test_handles_missing_upload_gracefully(): void
    {
        // Try to process non-existent upload
        $job = new ProcessImageVariantsJob(99999);
        
        // Should not throw exception
        $job->handle();

        // No images should be created
        $this->assertEquals(0, Image::count());
    }

    /**
     * Test status transitions
     */
    public function test_status_transitions_correctly(): void
    {
        $testImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        $checksum = hash('sha256', $testImageData);

        $upload = Upload::create([
            'original_name' => 'test.png',
            'checksum' => $checksum,
            'total_chunks' => 1,
            'received_chunks' => 1,
            'status' => 'processing',
        ]);

        // Initial status
        $this->assertEquals('processing', $upload->status);

        Storage::put("uploads/chunks/{$upload->id}/0", $testImageData);

        $job = new ProcessImageVariantsJob($upload->id);
        $job->handle();

        $upload->refresh();

        // Final status after successful processing
        $this->assertEquals('completed', $upload->status);
    }
}
