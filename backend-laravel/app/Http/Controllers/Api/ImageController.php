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
    private $imageUploadApiUrl = 'http://192.168.214.163:4000/api/uploads';

    /**
     * Serve file langsung dari filesystem server untuk path yang tidak bisa
     * dilayani oleh uploads API (mis: /data/cctv_images/ dari Node-RED).
     * Laravel berjalan di server yang sama sehingga bisa baca file secara langsung.
     */
    public function serveLocalFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'path' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $path = $request->input('path');

        // Batasi hanya path yang diizinkan agar tidak ada directory traversal
        $allowedPrefixes = [
            '/data/cctv_images/',
        ];

        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'Path tidak diizinkan',
            ], 403);
        }

        // Cegah directory traversal
        $realPath = realpath($path);
        if ($realPath === false) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan',
            ], 404);
        }

        // Pastikan realpath masih dalam direktori yang diizinkan
        $stillAllowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($realPath, $prefix)) {
                $stillAllowed = true;
                break;
            }
        }
        if (!$stillAllowed) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak',
            ], 403);
        }

        if (!is_file($realPath) || !is_readable($realPath)) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak dapat dibaca',
            ], 404);
        }

        $mimeType = mime_content_type($realPath) ?: 'image/jpeg';

        return response()->file($realPath, [
            'Content-Type'  => $mimeType,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

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

                    // Reject images larger than 10 MB to prevent OOM on base64 encode
                    if (strlen($imageData) > 10 * 1024 * 1024) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Gambar terlalu besar (maks 10 MB)',
                        ], 413);
                    }

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
