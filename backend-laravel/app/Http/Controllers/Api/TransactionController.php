<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\LogCctv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Get active transaction by plate number
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getActiveTransaction(Request $request): JsonResponse
    {
        $request->validate([
            'plate_number' => 'required|string',
        ]);

        // Normalize: trim whitespace and uppercase for case-insensitive match (PostgreSQL)
        $plateNumber = strtoupper(trim($request->query('plate_number')));

        // Find LATEST active transaction with the given plate number
        // Priority ordering:
        // 1. entry_time DESC (primary sort: newest entry_time)
        // 2. ID DESC (secondary sort)
        // Include logCctv relation to get view_image_path
        $transaction = Transaction::with([
            'logCctv', 'logAnprRecord', 'logAnprCctvRecord',
            'exitLogCctv', 'exitLogAnprRecord', 'exitLogAnprCctvRecord',
        ])
            ->whereRaw('UPPER(TRIM(plate_number)) = ?', [$plateNumber])
            ->where('status', Transaction::STATUS_ACTIVE)
            ->orderBy('entry_time', 'desc') // Primary sort: newest entry_time
            ->orderBy('id', 'desc') // Secondary sort: newest ID first
            ->first();

        if (!$transaction) {
            return $this->errorResponse(
                'Nomor plat tidak valid atau tidak memiliki transaksi aktif',
                404
            );
        }

        // Resolve every image source (MR columns + CCTV + ANPR relations) with clear labels.
        $this->attachResolvedImages($transaction);

        return $this->successResponse($transaction, 'Transaction found successfully.');
    }

    /**
     * Validate plate number (alias for getActiveTransaction)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validatePlate(Request $request): JsonResponse
    {
        return $this->getActiveTransaction($request);
    }

    /**
     * Complete a transaction (update status to COMPLETED)
     *
     * @param string $id
     * @return JsonResponse
     */
    public function completeTransaction(string $id): JsonResponse
    {
        $transaction = Transaction::find($id);

        if (!$transaction) {
            return $this->errorResponse('Transaction not found', 404);
        }

        if ($transaction->status === Transaction::STATUS_COMPLETED) {
            return $this->errorResponse('Transaction already completed', 400);
        }

        $transaction->status = Transaction::STATUS_COMPLETED;
        $transaction->exit_time = now();
        $transaction->flag = 1;
        $transaction->save();

        return $this->successResponse($transaction, 'Transaction completed successfully.');
    }

    /**
     * Get all transactions with optional filters
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query();

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        // Filter by plate number
        if ($request->has('plate_number')) {
            $query->byPlateNumber($request->query('plate_number'));
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('entry_time', '>=', $request->query('start_date'));
        }
        if ($request->has('end_date')) {
            $query->where('entry_time', '<=', $request->query('end_date'));
        }

        $perPage = $request->query('per_page', 15);
        $transactions = $query->orderBy('entry_time', 'desc')->paginate($perPage);

        return $this->paginatedResponse($transactions, 'Transactions retrieved successfully.');
    }

    /**
     * Get a single transaction
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $transaction = Transaction::with([
            'logCctv', 'logAnprRecord', 'logAnprCctvRecord',
            'exitLogCctv', 'exitLogAnprRecord', 'exitLogAnprCctvRecord'
        ])->find($id);

        if (!$transaction) {
            return $this->errorResponse('Transaction not found', 404);
        }

        // Resolve every image source (MR columns + CCTV + ANPR relations) with clear labels.
        $this->attachResolvedImages($transaction);

        return $this->successResponse($transaction, 'Transaction retrieved successfully.');
    }

    /**
     * Get a transaction by its code_transaction, with resolved images.
     * Used by the Gate History (Riwayat Gate) modal to show the full,
     * source-labeled image set for a historical manual gate action.
     *
     * @param string $code
     * @return JsonResponse
     */
    public function getByCode(string $code): JsonResponse
    {
        $transaction = Transaction::with([
            'logCctv', 'logAnprRecord', 'logAnprCctvRecord',
            'exitLogCctv', 'exitLogAnprRecord', 'exitLogAnprCctvRecord',
        ])
            ->whereRaw('UPPER(TRIM(code_transaction)) = ?', [strtoupper(trim($code))])
            ->orderBy('id', 'desc')
            ->first();

        if (!$transaction) {
            return $this->errorResponse('Transaction not found', 404);
        }

        $this->attachResolvedImages($transaction);

        return $this->successResponse($transaction, 'Transaction retrieved successfully.');
    }

    /**
     * Build a structured list of every image tied to the transaction and attach
     * it as `resolved_images` on the model, WITHOUT overwriting the MR image
     * columns (entry_image1..4 / exit_image1..4).
     *
     * Relation rules (per the business notes):
     *  - Entry uses `category`     with log_cctv_id / log_anpr_id
     *  - Exit  uses `category_exit` with exit_log_cctv_id / exit_log_anpr_id
     *  - category = get_capture     : anpr id  -> log_anpr  (plate + full image)
     *  - category = request_capture : anpr id  -> log_cctv  (view image)
     *  - the cctv id always         : cctv id  -> log_cctv  (view image)
     *
     * @param Transaction $transaction
     * @return void
     */
    private function attachResolvedImages(Transaction $transaction): void
    {
        $images = [];

        // ---- ENTRY (Masuk) ----
        // 1) MR images straight from the transactions table (source data dari MR).
        foreach (['entry_image_1', 'entry_image_2', 'entry_image_3', 'entry_image_4'] as $i => $field) {
            if (!empty($transaction->{$field})) {
                $images[] = [
                    'path'      => $transaction->{$field},
                    'label'     => 'MR (Masuk ' . ($i + 1) . ')',
                    'source'    => 'MR',
                    'direction' => 'entry',
                ];
            }
        }

        // 2) CCTV image via log_cctv_id -> log_cctv.
        if ($transaction->logCctv && !empty($transaction->logCctv->view_image_path)) {
            $transaction->view_image_path = $transaction->logCctv->view_image_path;
            $images[] = [
                'path'      => $transaction->logCctv->view_image_path,
                'label'     => 'CCTV (Masuk)',
                'source'    => 'CCTV',
                'direction' => 'entry',
            ];
        }

        // 3) ANPR image(s) via log_anpr_id, resolved by category.
        $entryAnpr = $transaction->entry_anpr_data;
        if ($entryAnpr) {
            if ($transaction->category === 'request_capture') {
                // log_anpr_id -> log_cctv (view image)
                if (!empty($entryAnpr->view_image_path)) {
                    $images[] = [
                        'path'      => $entryAnpr->view_image_path,
                        'label'     => 'ANPR·CCTV (Masuk)',
                        'source'    => 'ANPR',
                        'direction' => 'entry',
                    ];
                }
            } else {
                // get_capture: log_anpr_id -> log_anpr (1 gambar: full image)
                if (!empty($entryAnpr->full_image_path)) {
                    $images[] = [
                        'path'      => $entryAnpr->full_image_path,
                        'label'     => 'ANPR (Masuk)',
                        'source'    => 'ANPR',
                        'direction' => 'entry',
                    ];
                }
            }
        }

        // ---- EXIT (Keluar) ----
        // 1) MR images straight from the transactions table.
        foreach (['exit_image_1', 'exit_image_2', 'exit_image_3', 'exit_image_4'] as $i => $field) {
            if (!empty($transaction->{$field})) {
                $images[] = [
                    'path'      => $transaction->{$field},
                    'label'     => 'MR (Keluar ' . ($i + 1) . ')',
                    'source'    => 'MR',
                    'direction' => 'exit',
                ];
            }
        }

        // 2) CCTV image via exit_log_cctv_id -> log_cctv.
        if ($transaction->exitLogCctv && !empty($transaction->exitLogCctv->view_image_path)) {
            $transaction->exit_view_image_path = $transaction->exitLogCctv->view_image_path;
            $images[] = [
                'path'      => $transaction->exitLogCctv->view_image_path,
                'label'     => 'CCTV (Keluar)',
                'source'    => 'CCTV',
                'direction' => 'exit',
            ];
        }

        // 3) ANPR image(s) via exit_log_anpr_id, resolved by category_exit.
        $exitAnpr = $transaction->exit_anpr_data;
        if ($exitAnpr) {
            if ($transaction->category_exit === 'request_capture') {
                // exit_log_anpr_id -> log_cctv (view image)
                if (!empty($exitAnpr->view_image_path)) {
                    $images[] = [
                        'path'      => $exitAnpr->view_image_path,
                        'label'     => 'ANPR·CCTV (Keluar)',
                        'source'    => 'ANPR',
                        'direction' => 'exit',
                    ];
                }
            } else {
                // get_capture: exit_log_anpr_id -> log_anpr (1 gambar: full image)
                if (!empty($exitAnpr->full_image_path)) {
                    $images[] = [
                        'path'      => $exitAnpr->full_image_path,
                        'label'     => 'ANPR (Keluar)',
                        'source'    => 'ANPR',
                        'direction' => 'exit',
                    ];
                }
            }
        }

        $transaction->resolved_images = $images;
    }

    /**
     * Create a new transaction
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plate_number' => 'required|string|max:20',
            'entry_image1' => 'nullable|string',
            'entry_image2' => 'nullable|string',
            'entry_image3' => 'nullable|string',
            'entry_image4' => 'nullable|string',
            'qr_code' => 'nullable|string',
            'entry_time' => 'required|date',
            'status' => 'nullable|string|in:ACTIVE,COMPLETED',
            'notes' => 'nullable|string',
            'location' => 'nullable|string',
            'log_cctv_id' => 'nullable|integer',
            'log_anpr_id' => 'nullable|integer',
            'code_transaction' => 'nullable|string',
            'flag' => 'nullable|string',
            'user_id' => 'nullable|uuid',
        ]);

        // Set default status if not provided
        if (!isset($data['status'])) {
            $data['status'] = Transaction::STATUS_ACTIVE;
        }

        $transaction = Transaction::create($data);

        return $this->successResponse($transaction, 'Transaction created successfully.', 201);
    }

    /**
     * Update an existing transaction
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $transaction = Transaction::find($id);

        if (!$transaction) {
            return $this->errorResponse('Transaction not found', 404);
        }

        $data = $request->validate([
            'plate_number' => 'sometimes|required|string|max:20',
            'entry_image1' => 'nullable|string',
            'entry_image2' => 'nullable|string',
            'entry_image3' => 'nullable|string',
            'entry_image4' => 'nullable|string',
            'exit_image1' => 'nullable|string',
            'exit_image2' => 'nullable|string',
            'exit_image3' => 'nullable|string',
            'exit_image4' => 'nullable|string',
            'qr_code' => 'nullable|string',
            'entry_time' => 'sometimes|required|date',
            'exit_time' => 'nullable|date',
            'status' => 'sometimes|required|string|in:ACTIVE,COMPLETED',
            'notes' => 'nullable|string',
            'location' => 'nullable|string',
            'log_cctv_id' => 'nullable|integer',
            'log_anpr_id' => 'nullable|integer',
            'code_transaction' => 'nullable|string',
            'flag' => 'nullable|string',
            'user_id' => 'nullable|uuid',
        ]);

        $transaction->update($data);

        return $this->successResponse($transaction, 'Transaction updated successfully.');
    }

    /**
     * Delete a transaction
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        $transaction = Transaction::find($id);

        if (!$transaction) {
            return $this->errorResponse('Transaction not found', 404);
        }

        $transaction->delete();

        return $this->successResponse(null, 'Transaction deleted successfully.');
    }

    /**
     * Proxy request ke Node-RED CCTV capture API.
     * Frontend tidak perlu akses langsung ke Node-RED (hindari CORS).
     * Setelah capture berhasil, simpan image path ke log_cctv jika Node-RED
     * mengembalikan path (Format 3) agar record selalu terbuat.
     */
    public function cctvCapture(Request $request): JsonResponse
    {
        $request->validate([
            'device'  => 'required|string',
            'capture' => 'required|array',
        ]);

        $nodeRedUrl = env('NODE_RED_URL', 'http://127.0.0.1:1880') . '/cctv';

        try {
            $response = \Illuminate\Support\Facades\Http::withOptions([
                    'connect_timeout' => 5,
                    'timeout'         => 25,
                ])
                ->post($nodeRedUrl, [
                    'device'  => $request->input('device'),
                    'capture' => $request->input('capture'),
                ]);

            if ($response->failed()) {
                return $this->errorResponse('Node-RED capture gagal: HTTP ' . $response->status(), 502);
            }

            $data = $response->json();

            // Insert log_cctv records for any image paths returned by Node-RED.
            // Format 3: { data: { images: { view: { path: "..." }, anpr: { path: "..." } } } }
            // Node-RED may not auto-insert for dashboard/manual capture flows,
            // so we ensure the record always exists here.
            $this->persistLogCctvFromCapture($data, $request->input('device'));

            return response()->json($data);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return $this->errorResponse('Koneksi ke CCTV/Node-RED timeout: ' . $e->getMessage(), 504);
        } catch (\Exception $e) {
            return $this->errorResponse('Terjadi kesalahan pada capture CCTV: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Insert log_cctv records from a Node-RED capture response.
     * Handles Format 3: { data: { images: { view: { path: "..." }, anpr: { path: "..." } } } }
     * Silently skips if no paths are found (e.g. base64-only responses).
     */
    private function persistLogCctvFromCapture(array $data, string $device): void
    {
        $images = $data['data']['images'] ?? null;

        if (!is_array($images) || empty($images)) {
            return;
        }

        $now = now();

        foreach ($images as $type => $imgData) {
            $path = $imgData['path'] ?? null;
            if (empty($path)) {
                continue;
            }

            // Skip if this exact path is already recorded (avoid duplicates when
            // Node-RED also inserts the record for certain flows).
            $exists = LogCctv::where('view_image_path', $path)->exists();
            if ($exists) {
                continue;
            }

            try {
                LogCctv::create([
                    'cctv'            => $device,
                    'view_image_path' => $path,
                    'log_time'        => $now,
                    'flags'           => 0,
                ]);
            } catch (\Exception $e) {
                \Log::warning("persistLogCctvFromCapture: gagal insert untuk {$type}: " . $e->getMessage());
            }
        }
    }

    /**
     * Ambil 8 foto CCTV terbaru dari log_cctv (untuk slideshow dashboard).
     * Tidak dibatasi per kamera — ambil saja yang paling baru.
     */
    public function getLatestCctvSnapshots(Request $request): JsonResponse
    {
        try {
            $limit = min((int) $request->query('limit', 8), 20);

            $snapshots = LogCctv::whereNotNull('view_image_path')
                ->where('view_image_path', '!=', '')
                ->orderBy('log_time', 'desc')
                ->limit($limit)
                ->get(['id', 'cctv', 'view_image_path', 'log_time'])
                ->values()
                ->map(fn($r) => [
                    'id'              => $r->id,
                    'cctv'            => $r->cctv,
                    'view_image_path' => $r->view_image_path,
                    'log_time'        => $r->log_time,
                ]);

            return $this->successResponse($snapshots, 'Snapshots CCTV terbaru.');
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal mengambil snapshots: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Set flags=1 pada log_cctv record yang baru saja di-insert (dalam 30 detik terakhir).
     * Dibatasi ke record terbaru agar tidak salah mem-flag record lama yang tidak terkait.
     */
    public function setLogCctvFlags(Request $request): JsonResponse
    {
        $since = now()->subSeconds(30);

        $latest = LogCctv::where('log_time', '>=', $since)
            ->orderBy('log_time', 'desc')
            ->limit(4)
            ->get();

        // Fallback: jika tidak ada record dalam 30 detik terakhir, ambil 2 terbaru
        if ($latest->isEmpty()) {
            $latest = LogCctv::orderBy('log_time', 'desc')
                ->limit(2)
                ->get();
        }

        if ($latest->isEmpty()) {
            return $this->errorResponse('Tidak ada record log_cctv', 404);
        }

        $ids = $latest->pluck('id')->toArray();

        LogCctv::whereIn('id', $ids)->update(['flags' => 1]);

        return $this->successResponse(
            ['updated_ids' => $ids, 'flags' => 1],
            'Flags berhasil diupdate.'
        );
    }
}
