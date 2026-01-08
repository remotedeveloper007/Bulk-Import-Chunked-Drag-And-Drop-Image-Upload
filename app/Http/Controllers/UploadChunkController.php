<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Jobs\ProcessImageVariantsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Routing\Controller;

class UploadChunkController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'checksum' => 'required|string|max:64',
            'chunk_index' => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1',
            'original_name' => 'required|string',
            'chunk' => 'required|file',
        ]);

        $upload = Upload::firstOrCreate(
            ['checksum' => $request->input('checksum')],
            [
                'original_name' => $request->input('original_name'),
                'total_chunks' => $request->input('total_chunks'),
                'received_chunks' => json_encode([]),
                'status' => 'uploading',
            ]
        );

        $chunkIndex = $request->input('chunk_index');
        $receivedChunks = is_array($upload->received_chunks) ? $upload->received_chunks : [];

        // Only store chunk if not already received
        if (!in_array($chunkIndex, $receivedChunks)) {
            $chunkPath = "uploads/chunks/{$upload->id}/{$chunkIndex}";
            Storage::put(
                $chunkPath,
                file_get_contents($request->file('chunk'))
            );

            // Add this chunk to received list
            $receivedChunks[] = $chunkIndex;
            sort($receivedChunks); // Keep sorted for consistency
            
            $upload->update([
                'received_chunks' => $receivedChunks,
            ]);
        }

        // Recalculate received chunks count
        $upload->refresh();
        $receivedCount = count($upload->received_chunks ?? []);

        // If all chunks received, dispatch job
        if ($receivedCount === $upload->total_chunks) {
            $upload->update(['status' => 'processing']);
            dispatch(new ProcessImageVariantsJob($upload->id));
            
            return response()->json([
                'status' => 'completed',
                'upload_id' => $upload->id,
                'message' => 'All chunks received. Processing images...',
            ]);
        }

        return response()->json([
            'status' => 'uploading',
            'upload_id' => $upload->id,
            'received_chunks_count' => $receivedCount,
            'total_chunks' => $upload->total_chunks,
            'progress' => round(($receivedCount / $upload->total_chunks) * 100),
        ]);
    }
}
