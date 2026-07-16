<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CameraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live-CCTV camera configuration.
 *
 * Lets an operator change the RTSP source of each camera from the web UI. The
 * sources are persisted and pushed to go2rtc so the feeds update live (WebRTC).
 */
class CameraController extends Controller
{
    protected CameraService $cameras;

    public function __construct(CameraService $cameras)
    {
        $this->cameras = $cameras;
    }

    /**
     * Full camera list (includes RTSP URLs) for the management screen.
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            ['cameras' => $this->cameras->all()],
            'Konfigurasi kamera berhasil dimuat.'
        );
    }

    /**
     * Public-safe feed list (name + HLS only) for the dashboard grid.
     */
    public function feeds(): JsonResponse
    {
        return $this->successResponse(
            ['cameras' => $this->cameras->feeds()],
            'Daftar feed kamera berhasil dimuat.'
        );
    }

    /**
     * Save the submitted camera list and apply it to go2rtc.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cameras'            => ['present', 'array', 'max:4'],
            'cameras.*.name'     => ['nullable', 'string', 'max:100'],
            'cameras.*.rtsp_url' => ['nullable', 'string', 'max:500', 'regex:/^$|^rtsps?:\/\//i'],
            'cameras.*.enabled'  => ['nullable', 'boolean'],
        ], [
            'cameras.*.rtsp_url.regex' => 'URL kamera harus diawali dengan rtsp:// atau rtsps://.',
        ]);

        $result = $this->cameras->save($data['cameras']);

        return $this->successResponse(
            $result,
            'Konfigurasi kamera berhasil disimpan.'
        );
    }

    /**
     * Re-push the stored configuration to go2rtc (e.g. after restarting it).
     */
    public function apply(): JsonResponse
    {
        return $this->successResponse(
            ['apply' => $this->cameras->applyToGo2rtc()],
            'Konfigurasi kamera diterapkan ke go2rtc.'
        );
    }
}
