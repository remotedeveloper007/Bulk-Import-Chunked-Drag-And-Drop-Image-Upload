<?php

use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\UploadChunkController;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// });

Route::get('/', fn () => view('bulk-csv-import'));

Route::post('/import/products', ProductImportController::class)->name('products.import');
Route::post('/upload/chunk', UploadChunkController::class)->name('upload.chunk');
Route::post('/products/{product}/attach-image/{upload}', [ProductImportController::class, 'attachImage'])->name('products.attach-image');
