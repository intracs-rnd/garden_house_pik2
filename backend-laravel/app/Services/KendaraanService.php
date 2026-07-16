<?php

namespace App\Services;

use App\Repositories\KendaraanRepository;

class KendaraanService
{
    protected KendaraanRepository $kendaraanRepository;

    public function __construct(KendaraanRepository $kendaraanRepository)
    {
        $this->kendaraanRepository = $kendaraanRepository;
    }

    /**
     * Paginated, filterable list of vehicles.
     */
    public function list(array $filters = [], int $perPage = 15)
    {
        return $this->kendaraanRepository->filter($filters, $perPage);
    }

    /**
     * Get a single vehicle (with relations) by id.
     *
     * @return \App\Models\Kendaraan
     */
    public function find($id)
    {
        return $this->kendaraanRepository->findOrFail($id)->load(['user']);
    }

    /**
     * Create a new vehicle.
     *
     * @return \App\Models\Kendaraan
     */
    public function create(array $data)
    {
        return $this->kendaraanRepository->create($data)->load(['user']);
    }

    /**
     * Update an existing vehicle.
     *
     * @return \App\Models\Kendaraan
     */
    public function update($id, array $data)
    {
        return $this->kendaraanRepository->update($id, $data)->load(['user']);
    }

    /**
     * Delete a vehicle.
     */
    public function delete($id): bool
    {
        return $this->kendaraanRepository->delete($id);
    }
}
