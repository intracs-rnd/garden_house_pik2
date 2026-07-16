<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KendaraanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KendaraanController extends Controller
{
    protected KendaraanService $kendaraanService;

    public function __construct(KendaraanService $kendaraanService)
    {
        $this->kendaraanService = $kendaraanService;
    }

    /**
     * Display a paginated listing of vehicles.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'status', 'user_id']);

        $kendaraans = $this->kendaraanService->list(
            $filters,
            (int) $request->query('per_page', 15)
        );

        return $this->paginatedResponse($kendaraans, 'Vehicles retrieved successfully.');
    }

    /**
     * Store a newly created vehicle.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'    => ['nullable', 'exists:users,id'],
            'nama'       => ['required', 'string', 'max:255'],
            'nomor_plat' => ['required', 'string', 'max:20', 'unique:kendaraans,nomor_plat'],
            'merk'       => ['nullable', 'string', 'max:100'],
            'model'      => ['nullable', 'string', 'max:100'],
            'tahun'      => ['nullable', 'integer', 'digits:4'],
        ]);

        $kendaraan = $this->kendaraanService->create($data);

        return $this->successResponse($kendaraan, 'Vehicle created successfully.', 201);
    }

    /**
     * Display the specified vehicle.
     */
    public function show($id): JsonResponse
    {
        $kendaraan = $this->kendaraanService->find($id);

        return $this->successResponse($kendaraan, 'Vehicle retrieved successfully.');
    }

    /**
     * Update the specified vehicle.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $data = $request->validate([
            'user_id'    => ['sometimes', 'nullable', 'exists:users,id'],
            'nama'       => ['sometimes', 'required', 'string', 'max:255'],
            'nomor_plat' => [
                'sometimes', 'required', 'string', 'max:20',
                Rule::unique('kendaraans', 'nomor_plat')
                    ->ignore($id)
                    ->where(fn ($query) => $query->where('is_deleted', false)),
            ],
            'merk'       => ['nullable', 'string', 'max:100'],
            'model'      => ['nullable', 'string', 'max:100'],
            'tahun'      => ['nullable', 'integer', 'digits:4'],
        ]);

        $kendaraan = $this->kendaraanService->update($id, $data);

        return $this->successResponse($kendaraan, 'Vehicle updated successfully.');
    }

    /**
     * Remove the specified vehicle.
     */
    public function destroy($id): JsonResponse
    {
        $this->kendaraanService->delete($id);

        return $this->successResponse(null, 'Vehicle deleted successfully.');
    }
}
