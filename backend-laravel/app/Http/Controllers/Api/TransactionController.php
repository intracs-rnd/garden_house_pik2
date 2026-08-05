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
     */
    public function cctvCapture(Request $request): JsonResponse
    {
        $request->validate([
            'device'  => 'required|string',
            'capture' => 'required|array',
        ]);

        $nodeRedUrl = 'http://192.168.214.163:1880/cctv';

        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->post($nodeRedUrl, [
                'device'  => $request->input('device'),
                'capture' => $request->input('capture'),
            ]);

        if ($response->failed()) {
            return $this->errorResponse('Node-RED capture gagal: HTTP ' . $response->status(), 502);
        }

        return response()->json($response->json());
    }

    /**
     * Set flags=1 pada 2 log_cctv record terbaru (by log_time).
     * Node-RED insert 2 record sekaligus (anpr + view) saat capture,
     * sehingga keduanya perlu di-update.
     */
    public function setLogCctvFlags(Request $request): JsonResponse
    {
        $latest = LogCctv::orderBy('log_time', 'desc')
            ->limit(2)
            ->get();

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
