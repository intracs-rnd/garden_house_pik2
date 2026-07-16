<?php

namespace App\Repositories;

use App\Models\Kendaraan;

class KendaraanRepository extends BaseRepository
{
    public function __construct(Kendaraan $model)
    {
        parent::__construct($model);
    }

    /**
     * Paginate vehicles with optional filters and eager-loaded relations.
     */
    public function filter(array $filters = [], int $perPage = 15)
    {
        return $this->model->newQuery()
            ->with(['user'])
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('nama', 'ilike', "%{$search}%")
                        ->orWhere('nomor_plat', 'ilike', "%{$search}%")
                        ->orWhere('merk', 'ilike', "%{$search}%");
                });
            })
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Find a vehicle by license plate number.
     *
     * @return \App\Models\Kendaraan|null
     */
    public function findByNomorPlat(string $nomorPlat)
    {
        return $this->findBy('nomor_plat', $nomorPlat);
    }

    /**
     * Soft delete a vehicle: flag it as deleted (is_deleted = true)
     * instead of permanently removing the record.
     */
    public function delete($id): bool
    {
        return $this->findOrFail($id)->softDelete();
    }

    /**
     * Restore a previously soft-deleted vehicle.
     */
    public function restore($id): bool
    {
        return $this->model->newQuery()
            ->withDeleted()
            ->findOrFail($id)
            ->restore();
    }
}
