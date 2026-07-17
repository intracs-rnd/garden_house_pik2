<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ImageController extends Controller
{
    /**
     * API endpoint untuk image upload service
     */
    private $imageUploadApiUrl = 'http://192.168.214.7:4000/api/uploads';

    /**
     * Mengambil gambar dari API upload service berdasarkan path
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetchImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'path' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Kirim request ke image upload API
            $response = Http::timeout(30)->post($this->imageUploadApiUrl, [
                'path' => $request->path,
            ]);

            if ($response->successful()) {
                $contentType = $response->header('Content-Type');
                
                // Jika response adalah binary image (JPEG/PNG/etc)
                if ($contentType && str_contains($contentType, 'image')) {
                    $imageData = $response->body();
                    $base64 = base64_encode($imageData);
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Gambar berhasil diambil',
                        'data' => [
                            'content_type' => $contentType,
                            'size' => strlen($imageData),
                            'base64' => $base64,
                            'url' => 'data:' . $contentType . ';base64,' . $base64,
                            'path' => $request->path,
                        ],
                    ], 200);
                }
                
                // Jika API mengembalikan JSON dengan URL gambar
                $jsonData = $response->json();
                if ($jsonData) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Gambar berhasil diambil',
                        'data' => $jsonData,
                    ], 200);
                }
                
                // Fallback: return raw body
                return response()->json([
                    'success' => true,
                    'message' => 'Gambar berhasil diambil',
                    'data' => [
                        'raw' => substr($response->body(), 0, 100) . '...',
                        'size' => strlen($response->body()),
                    ],
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil gambar dari API',
                'error' => $response->body(),
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saat menghubungi image upload API',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batch fetch multiple images
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetchMultipleImages(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'paths' => 'required|array|min:1',
            'paths.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $results = [];
        $errors = [];

        foreach ($request->paths as $path) {
            try {
                $response = Http::timeout(30)->post($this->imageUploadApiUrl, [
                    'path' => $path,
                ]);

                if ($response->successful()) {
                    $results[] = [
                        'path' => $path,
                        'success' => true,
                        'data' => $response->json(),
                    ];
                } else {
                    $errors[] = [
                        'path' => $path,
                        'success' => false,
                        'error' => $response->body(),
                    ];
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'path' => $path,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Proses batch selesai',
            'results' => $results,
            'errors' => $errors,
            'total' => count($request->paths),
            'succeeded' => count($results),
            'failed' => count($errors),
        ], 200);
    }
}
