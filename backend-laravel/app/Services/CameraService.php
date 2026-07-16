<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Manages the live-CCTV camera configuration.
 *
 * The RTSP source URLs are stored in a small JSON file (no database table
 * required) and pushed to the go2rtc REST API at runtime, so an operator can
 * change a camera by editing the form in the web UI instead of touching
 * `go2rtc.yaml` or the code. go2rtc restreams each RTSP feed as WebRTC, so the
 * dashboard plays it with sub-second latency (no HLS delay).
 */
class CameraService
{
    /** Relative path of the JSON store on the "local" filesystem disk. */
    protected const STORE = 'cameras.json';

    /** Number of camera slots exposed by the dashboard grid. */
    protected const SLOTS = 4;

    /**
     * Full camera list including the (sensitive) RTSP URL. For the management
     * screen only.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $stored = $this->read();

        $cameras = [];
        foreach ($this->defaults() as $i => $default) {
            $current = $stored[$i] ?? [];

            $cameras[] = [
                'path'       => $default['path'],
                'name'       => $current['name'] ?? $default['name'],
                'rtsp_url'   => $current['rtsp_url'] ?? $default['rtsp_url'],
                'enabled'    => array_key_exists('enabled', $current)
                    ? (bool) $current['enabled']
                    : $default['enabled'],
                'stream_url' => $this->streamUrl($default['path']),
            ];
        }

        return $cameras;
    }

    /**
     * Public-safe feed list for the dashboard: no RTSP URL / credentials, just
     * what the player needs (display name + WebRTC signalling URL).
     *
     * @return array<int, array<string, mixed>>
     */
    public function feeds(): array
    {
        return array_map(function ($cam) {
            return [
                'path'       => $cam['path'],
                'name'       => $cam['name'],
                'stream_url' => $cam['stream_url'],
                'enabled'    => $cam['enabled'],
            ];
        }, $this->all());
    }

    /**
     * Persist the submitted camera list and push the sources to go2rtc.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{cameras: array, apply: array}
     */
    public function save(array $items): array
    {
        $defaults = $this->defaults();
        $normalized = [];

        for ($i = 0; $i < self::SLOTS; $i++) {
            $item = $items[$i] ?? [];
            $name = trim((string) ($item['name'] ?? $defaults[$i]['name']));

            $normalized[] = [
                'path'     => $defaults[$i]['path'], // path is fixed (cam1..cam4)
                'name'     => $name !== '' ? $name : $defaults[$i]['name'],
                'rtsp_url' => trim((string) ($item['rtsp_url'] ?? '')),
                'enabled'  => (bool) ($item['enabled'] ?? false),
            ];
        }

        Storage::disk('local')->put(
            self::STORE,
            json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return [
            'cameras' => $this->all(),
            'apply'   => $this->applyToGo2rtc(),
        ];
    }

    /**
     * Push every configured camera source to the go2rtc REST API so the live
     * feeds update without restarting the streamer.
     *
     * @return array<int, array<string, mixed>>  One status entry per camera.
     */
    public function applyToGo2rtc(): array
    {
        $results = [];

        foreach ($this->all() as $cam) {
            $results[] = $this->pushCamera($cam);
        }

        return $results;
    }

    /**
     * Add, update or remove a single go2rtc stream from a camera definition.
     *
     * @param  array<string, mixed>  $cam
     * @return array<string, mixed>
     */
    protected function pushCamera(array $cam): array
    {
        $status = [
            'path'   => $cam['path'],
            'name'   => $cam['name'],
            'status' => 'skipped',
            'detail' => '',
        ];

        try {
            // Disabled or empty camera: remove its stream from go2rtc (if any).
            if (empty($cam['enabled']) || $cam['rtsp_url'] === '') {
                $query = http_build_query(['src' => $cam['path']]);
                $this->api()->delete('/api/streams?'.$query);
                $status['detail'] = 'Kamera nonaktif atau URL RTSP kosong — stream dihapus.';
                return $status;
            }

            // Create or replace the stream so go2rtc pulls this RTSP source and
            // restreams it as WebRTC. go2rtc reads params from the query string
            // (not the body), and this also persists the source to go2rtc.yaml.
            $query = http_build_query([
                'name' => $cam['path'],
                'src'  => $cam['rtsp_url'],
            ]);
            $response = $this->api()->put('/api/streams?'.$query);

            if ($response->successful()) {
                $status['status'] = 'applied';
                $status['detail'] = 'Sumber diterapkan ke go2rtc.';
            } else {
                $status['status'] = 'failed';
                $status['detail'] = 'go2rtc menolak permintaan (HTTP '.$response->status().').';
            }
        } catch (\Throwable $e) {
            $status['status'] = 'unreachable';
            $status['detail'] = 'Tidak dapat menghubungi go2rtc. Pastikan streamer berjalan '
                .'(streaming/start-stream.ps1) dan API aktif.';
        }

        return $status;
    }

    /** Pre-configured HTTP client for the go2rtc REST API. */
    protected function api()
    {
        return Http::baseUrl(rtrim((string) config('services.go2rtc.api_url'), '/'))
            ->acceptJson()
            ->timeout(5);
    }

    /** Build the browser WebRTC signalling (WebSocket) URL for a go2rtc stream. */
    protected function streamUrl(string $path): string
    {
        $base = rtrim((string) config('services.go2rtc.stream_url'), '/');

        return "{$base}/api/ws?src={$path}";
    }

    /**
     * Read the raw stored camera list (may be empty / partial).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function read(): array
    {
        if (! Storage::disk('local')->exists(self::STORE)) {
            return [];
        }

        $decoded = json_decode(Storage::disk('local')->get(self::STORE), true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Factory defaults for the four camera slots. `cam1` ships with the sample
     * camera already installed so the feature works out of the box.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function defaults(): array
    {
        return [
            ['path' => 'cam1', 'name' => 'Kamera 1', 'rtsp_url' => 'rtsp://root:cctv123456@192.168.203.119:554/live2.sdp', 'enabled' => true],
            ['path' => 'cam2', 'name' => 'Kamera 2', 'rtsp_url' => '', 'enabled' => false],
            ['path' => 'cam3', 'name' => 'Kamera 3', 'rtsp_url' => '', 'enabled' => false],
            ['path' => 'cam4', 'name' => 'Kamera 4', 'rtsp_url' => '', 'enabled' => false],
        ];
    }
}
