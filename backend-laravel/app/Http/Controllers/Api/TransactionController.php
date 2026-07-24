<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
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
        $transaction = Transaction::with('logCctv')
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

        // If logCctv exists, add view_image_path to the response
        if ($transaction->logCctv && $transaction->logCctv->view_image_path) {
            $transaction->view_image_path = $transaction->logCctv->view_image_path;
        }

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
        $transaction = Transaction::find($id);

        if (!$transaction) {
            return $this->errorResponse('Transaction not found', 404);
        }

        return $this->successResponse($transaction, 'Transaction retrieved successfully.');
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
}
