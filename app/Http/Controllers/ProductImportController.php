<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Actions\ImportProductsAction;
use App\Validation\CsvValidator;

class ProductImportController extends Controller
{
    /**
     * Handle CSV file upload and trigger import
     *
     * @param Request $request
     * @param ImportProductsAction $action
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request, ImportProductsAction $action)
    {
        // Validate file upload
        $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:104857600'], // 100MB max
        ]);

        $file = $request->file('csv');
        $filePath = $file->path();

        // Validate CSV structure
        $validation = CsvValidator::validate($filePath, ['sku', 'name', 'price']);
        
        if (!$validation['valid']) {
            return response()->json([
                'success' => false,
                'errors' => $validation['errors'],
            ], 422);
        }

        // Validate file size
        $sizeValidation = CsvValidator::validateSize($filePath);
        if (!$sizeValidation['valid']) {
            return response()->json([
                'success' => false,
                'error' => $sizeValidation['error'],
            ], 422);
        }

        // Execute import
        $result = $action->execute($filePath);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Attach an uploaded image to a product as its primary image
     *
     * @param Request $request
     * @param \App\Models\Product $product
     * @param \App\Models\Upload $upload
     * @return \Illuminate\Http\JsonResponse
     */
    public function attachImage(Request $request, $product, $upload)
    {
        $product = \App\Models\Product::findOrFail($product);
        $upload = \App\Models\Upload::findOrFail($upload);

        // Validate upload is completed
        if ($upload->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Upload is not yet completed',
            ], 422);
        }

        // Get the first image variant (original or largest)
        $image = $upload->images()->orderByDesc('width')->first();

        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'No images found for this upload',
            ], 422);
        }

        // Attach as primary image
        $product->primary_image_id = $image->id;
        $product->save();

        return response()->json([
            'success' => true,
            'message' => 'Image attached successfully',
            'data' => [
                'product' => $product->fresh(),
                'image' => $image,
            ],
        ]);
    }
}

