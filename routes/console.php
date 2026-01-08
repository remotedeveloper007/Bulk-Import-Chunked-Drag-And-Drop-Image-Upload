<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use App\Models\Upload;
use App\Jobs\ProcessImageVariantsJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Generate a sample CSV using uploaded image filenames (status=completed)
Artisan::command('csv:sample-with-images {--limit=50}', function () {
    $limit = (int) $this->option('limit');
    $limit = $limit > 0 ? $limit : 50;

    $uploads = Upload::where('status', 'completed')
        ->orderByDesc('created_at')
        ->limit($limit)
        ->get(['original_name']);

    if ($uploads->isEmpty()) {
        $this->warn('No completed uploads found. Upload images first via UI.');
        return 1;
    }

    $rows = [];
    $rows[] = ['sku', 'name', 'price', 'image'];

    $i = 1;
    foreach ($uploads as $upload) {
        $sku = sprintf('SKU%05d', $i);
        $name = 'Sample Product ' . $i;
        $price = number_format(10 + ($i % 90) + ($i % 100)/100, 2);
        $image = $upload->original_name;
        $rows[] = [$sku, $name, $price, $image];
        $i++;
    }

    $csv = '';
    foreach ($rows as $row) {
        $csv .= implode(',', array_map(function ($v) {
            $v = (string) $v;
            return str_contains($v, ',') ? '"' . $v . '"' : $v;
        }, $row)) . "\n";
    }

    $path = 'samples/products_with_images.csv';
    Storage::disk('local')->put($path, $csv);
    $this->info('Generated: ' . storage_path('app/' . $path));
    $this->line('Import it via UI to verify image linking.');
    return 0;
})->purpose('Generate sample products CSV with image column from completed uploads');

// Generate a large sample CSV (10,000 rows) without images
Artisan::command('csv:sample-10k', function () {
    $rows = [];
    $rows[] = ['sku', 'name', 'price'];
    for ($i = 1; $i <= 10000; $i++) {
        $rows[] = [
            sprintf('SKU%05d', $i),
            'Product ' . $i,
            number_format(mt_rand(1000, 9999)/100, 2),
        ];
    }

    $csv = '';
    foreach ($rows as $row) {
        $csv .= implode(',', $row) . "\n";
    }

    $path = 'samples/products_10000.csv';
    Storage::disk('local')->put($path, $csv);
    $this->info('Generated: ' . storage_path('app/' . $path));
    $this->line('Use it to validate import performance and summary table.');
    return 0;
})->purpose('Generate 10,000-row sample products CSV (no images)');

// Process any pending uploads synchronously (without running queue worker)
Artisan::command('uploads:process-pending {--limit=100}', function () {
    $limit = (int) $this->option('limit');
    $limit = $limit > 0 ? $limit : 100;

    $pending = Upload::whereIn('status', ['processing'])
        ->orderBy('id')
        ->limit($limit)
        ->get(['id', 'status']);

    if ($pending->isEmpty()) {
        $this->info('No pending uploads found (status=processing).');
        return 0;
    }

    $processed = 0;
    foreach ($pending as $upload) {
        $this->line('Processing upload ID: ' . $upload->id);
        // Run job synchronously
        (new ProcessImageVariantsJob($upload->id))->handle();
        $processed++;
    }

    $this->info("Processed {$processed} uploads.");
    $this->line('These uploads should now be status=completed if checksums matched and chunks existed.');
    return 0;
})->purpose('Synchronously process pending uploads into image variants');
