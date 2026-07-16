<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KartuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KartuController extends Controller
{
    protected KartuService $kartuService;

    public function __construct(KartuService $kartuService)
    {
        $this->kartuService = $kartuService;
    }

    /**
     * Display a paginated listing of access cards.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'user_id', 'is_blacklisted']);

        $kartus = $this->kartuService->list(
            $filters,
            (int) $request->query('per_page', 15)
        );

        return $this->paginatedResponse($kartus, 'Access cards retrieved successfully.');
    }

    /**
     * Store a newly created access card.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'          => ['required', 'exists:users,id'],
            'card_number'      => [
                'nullable', 'string', 'max:50',
                Rule::unique('kartus', 'card_number')
                    ->where(fn ($query) => $query->where('is_deleted', false)),
            ],
            'rfid_tag'         => [
                'nullable', 'string', 'max:100',
                Rule::unique('kartus', 'rfid_tag')
                    ->where(fn ($query) => $query->where('is_deleted', false)),
            ],
            'nama'             => ['nullable', 'string', 'max:255'],
            'status'           => ['nullable', 'integer', 'in:1,2,3'],
            'is_blacklisted'   => ['nullable', 'boolean'],
            'blacklist_reason' => ['nullable', 'string', 'max:255'],
            'valid_from'       => ['nullable', 'date'],
            'valid_until'      => ['nullable', 'date', 'after_or_equal:valid_from'],
            'grace_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'keterangan'       => ['nullable', 'string'],
        ]);

        $kartu = $this->kartuService->create($data);

        return $this->successResponse($kartu, 'Access card created successfully.', 201);
    }

    /**
     * Display the specified access card.
     */
    public function show($id): JsonResponse
    {
        $kartu = $this->kartuService->find($id);

        return $this->successResponse($kartu, 'Access card retrieved successfully.');
    }

    /**
     * Update the specified access card.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $data = $request->validate([
            'user_id'          => ['sometimes', 'required', 'exists:users,id'],
            'card_number'      => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('kartus', 'card_number')
                    ->ignore($id)
                    ->where(fn ($query) => $query->where('is_deleted', false)),
            ],
            'rfid_tag'         => [
                'nullable', 'string', 'max:100',
                Rule::unique('kartus', 'rfid_tag')
                    ->ignore($id)
                    ->where(fn ($query) => $query->where('is_deleted', false)),
            ],
            'nama'             => ['nullable', 'string', 'max:255'],
            'status'           => ['nullable', 'integer', 'in:1,2,3'],
            'is_blacklisted'   => ['nullable', 'boolean'],
            'blacklist_reason' => ['nullable', 'string', 'max:255'],
            'valid_from'       => ['nullable', 'date'],
            'valid_until'      => ['nullable', 'date', 'after_or_equal:valid_from'],
            'grace_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'keterangan'       => ['nullable', 'string'],
        ]);

        $kartu = $this->kartuService->update($id, $data);

        return $this->successResponse($kartu, 'Access card updated successfully.');
    }

    /**
     * Remove the specified access card.
     */
    public function destroy($id): JsonResponse
    {
        $this->kartuService->delete($id);

        return $this->successResponse(null, 'Access card deleted successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | Gate endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * Handle a tab-in (entry) tap at the gate.
     */
    public function tabIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_number' => ['required', 'string', 'max:50'],
            'gate'        => ['nullable', 'string', 'max:50'],
            'no_plat'     => ['nullable', 'string', 'max:20'],
        ]);

        $result = $this->kartuService->tabIn($data['card_number'], [
            'gate'    => $data['gate'] ?? null,
            'no_plat' => $data['no_plat'] ?? null,
        ]);

        return $this->accessResponse($result);
    }

    /**
     * Handle a tab-out (exit) tap at the gate.
     */
    public function tabOut(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_number' => ['required', 'string', 'max:50'],
            'gate'        => ['nullable', 'string', 'max:50'],
            'no_plat'     => ['nullable', 'string', 'max:20'],
        ]);

        $result = $this->kartuService->tabOut($data['card_number'], [
            'gate'    => $data['gate'] ?? null,
            'no_plat' => $data['no_plat'] ?? null,
        ]);

        return $this->accessResponse($result);
    }

    /**
     * Check the current access status of a card by id.
     */
    public function status($id): JsonResponse
    {
        $status = $this->kartuService->statusById($id);

        return $this->successResponse($status, 'Card status retrieved successfully.');
    }

    /**
     * Check the current access status of a card by its number.
     */
    public function statusByNumber(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_number' => ['required', 'string', 'max:50'],
        ]);

        $status = $this->kartuService->checkStatus($data['card_number']);

        return $this->successResponse($status, 'Card status retrieved successfully.');
    }

    /**
     * Blacklist a card (e.g. because of unpaid dues).
     */
    public function blacklist(Request $request, $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $kartu = $this->kartuService->blacklist($id, $data['reason'] ?? null);

        return $this->successResponse($kartu, 'Access card blacklisted successfully.');
    }

    /**
     * Remove a card from the blacklist.
     */
    public function clearBlacklist($id): JsonResponse
    {
        $kartu = $this->kartuService->clearBlacklist($id);

        return $this->successResponse($kartu, 'Access card reactivated successfully.');
    }

    /**
     * Access log history for a card.
     */
    public function logs(Request $request, $id): JsonResponse
    {
        $logs = $this->kartuService->logs($id, (int) $request->query('per_page', 15));

        return $this->paginatedResponse($logs, 'Access logs retrieved successfully.');
    }

    /**
     * Recent access log history across all cards (for the gate console).
     */
    public function accessLogs(Request $request): JsonResponse
    {
        $filters = $request->only(['card_number', 'direction', 'access_granted', 'user_id', 'kartu_id']);

        $logs = $this->kartuService->recentLogs(
            $filters,
            (int) $request->query('per_page', 15)
        );

        return $this->paginatedResponse($logs, 'Access logs retrieved successfully.');
    }

    /**
     * Build a gate access response. Always HTTP 200 so gate devices can read
     * the boolean flag; denial detail is carried in the payload.
     */
    protected function accessResponse(array $result): JsonResponse
    {
        $message = $result['message'] ?? ($result['access_granted'] ? 'Access granted.' : 'Access denied.');

        return $this->successResponse($result, $message);
    }
}
