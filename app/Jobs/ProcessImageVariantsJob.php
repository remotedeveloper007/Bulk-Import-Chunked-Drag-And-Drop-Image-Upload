<?php

namespace App\Jobs;

use App\Models\Upload;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Image as ImageModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\Log;

/**
 * ProcessImageVariantsJob
 *
 * Generates multiple image variants (256px, 512px, 1024px) from an uploaded image.
 * 
 * Task Requirements:
 * - Generate 3 variants: 256px, 512px, 1024px
 * - Maintain aspect ratio
 * - Checksum validation before processing (mismatch blocks completion)
 * - Concurrent upload safety (lockForUpdate)
 * - Idempotent variant generation
 * - Re-sending chunks must not corrupt data
 * - Storage-based approach compatible with Storage::fake()
 */
class ProcessImageVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload ID to process
     *
     * @var int
     */
    public int $uploadId;

    /**
     * Create a new job instance.
     *
     * @param int $uploadId Upload ID to process
     */
    public function __construct(int $uploadId)
    {
        $this->uploadId = $uploadId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function () {
            // Lock the upload row to prevent concurrent processing (Task requirement: concurrency-safe)
            $upload = Upload::where('id', $this->uploadId)->lockForUpdate()->first();

            // Upload not found - gracefully exit
            if (!$upload) {
                return;
            }

            // Already completed - idempotent behavior (Task requirement)
            if ($upload->status === 'completed') {
                return;
            }

            // Skip if already failed
            if ($upload->status === 'failed') {
                return;
            }

            try {
                // Assemble file from chunks stored in Storage
                $assembledPath = $this->assembleFile($upload);

                // Verify checksum (Task requirement: checksum mismatch blocks completion)
                $actualChecksum = hash('sha256', Storage::get($assembledPath));

                if ($actualChecksum !== $upload->checksum) {
                    $upload->update(['status' => 'failed']);
                    Storage::delete($assembledPath);
                    return;
                }

                // Generate image variants (Task requirement: 256px, 512px, 1024px)
                $manager = new ImageManager(new Driver());

                foreach ([256, 512, 1024] as $size) {
                    // Read from storage (compatible with Storage::fake())
                    $imageContent = Storage::get($assembledPath);
                    $image = $manager->read($imageContent);

                    // Resize maintaining aspect ratio (Task requirement)
                    $image->scale(width: $size);

                    $variantPath = "images/{$upload->id}_{$size}.jpg";

                    // Save to storage
                    Storage::put($variantPath, (string) $image->encode());

                    // Create image record (idempotent via firstOrCreate)
                    ImageModel::firstOrCreate([
                        'upload_id' => $upload->id,
                        'variant' => (string) $size,
                    ], [
                        'path' => $variantPath,
                        'width' => $image->width(),
                        'height' => $image->height(),
                        'checksum' => $actualChecksum,
                    ]);
                }

                // Mark as completed
                $upload->update(['status' => 'completed']);

                // Cleanup assembled file
                Storage::delete($assembledPath);

            } catch (\Exception $e) {
                // Mark as failed on any error
                $upload->update(['status' => 'failed']);
                Log::error("Image variant generation failed for upload {$upload->id}: " . $e->getMessage());
            }
        });
    }

    /**
     * Assemble the file from chunks stored in Storage
     * 
     * Task requirement: Re-sending chunks must not corrupt data
     * (uses Storage::exists to validate all chunks are present)
     *
     * @param Upload $upload
     * @return string Path in storage to assembled file
     * @throws \Exception if chunks are missing
     */
    private function assembleFile(Upload $upload): string
    {
        $assembledPath = "uploads/assembled/{$upload->id}";

        // Check all chunks exist before assembling (Task requirement: prevent corruption)
        for ($i = 0; $i < $upload->total_chunks; $i++) {
            $chunkPath = "uploads/chunks/{$upload->id}/{$i}";
            
            if (!Storage::exists($chunkPath)) {
                throw new \Exception("Chunk {$i} not found for upload {$upload->id}");
            }
        }

        // Assemble chunks into one file
        $assembledContent = '';
        for ($i = 0; $i < $upload->total_chunks; $i++) {
            $chunkPath = "uploads/chunks/{$upload->id}/{$i}";
            $assembledContent .= Storage::get($chunkPath);
        }

        // Write assembled file to storage
        Storage::put($assembledPath, $assembledContent);

        return $assembledPath;
    }
}

