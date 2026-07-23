<?php

namespace App\Repositories;

use App\Models\Kartu;

/**
 * Data-access layer untuk tabel `kartus`.
 *
 * READ/WRITE routing (otomatis via koneksi `pgsql`):
 *   READ  (SELECT) → 192.168.214.161  – PC Admin replica, data cepat
 *   WRITE (DML)    → 192.168.214.163  – Virtual IP master, tambah/edit kartu
 *
 * Logical Replication dari 163 → 161 berjalan real-time sehingga
 * data di READ host selalu segar dalam hitungan milidetik.
 */
class KartuRepository extends BaseRepository
{
    public function __construct(Kartu $model)
    {
        parent::__construct($model);
    }

    /**
     * Paginate cards with optional filters and eager-loaded owner.
     *
     * READ → 192.168.214.161 (SELECT dikirim ke replica)
     *
     * @param  array<string, mixed>  $filters
     * @param  int  $perPage
     * @param  bool  $includeDeleted  Include soft-deleted cards (is_deleted = true)
     */
    public function filter(array $filters = [], int $perPage = 15, bool $includeDeleted = false)
    {
        $query = $this->model->newQuery();

        // When the deleted tab is requested, return only deleted cards
        if ($includeDeleted) {
            $query->onlyDeleted();
        }

        return $query
            ->with('user')
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('card_number', 'ilike', "%{$search}%")
                        ->orWhere('rfid_tag', 'ilike', "%{$search}%")
                        ->orWhere('nama', 'ilike', "%{$search}%")
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'ilike', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when(isset($filters['is_blacklisted']) && $filters['is_blacklisted'] !== '', function ($query) use ($filters) {
                $query->where('is_blacklisted', filter_var($filters['is_blacklisted'], FILTER_VALIDATE_BOOLEAN));
            })
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Find a card by its physical card number / UID.
     *
     * READ → 192.168.214.161 (lookup saat gate tap — data replica sudah segar)
     *
     * @return \App\Models\Kartu|null
     */
    public function findByCardNumber(string $cardNumber)
    {
        return $this->model->newQuery()
            ->with('user')
            ->where('card_number', $cardNumber)
            ->first();
    }

    /**
     * Find a card by its physical RFID tag / UID.
     *
     * READ → 192.168.214.161
     *
     * @return \App\Models\Kartu|null
     */
    public function findByRfidTag(string $rfidTag)
    {
        return $this->model->newQuery()
            ->with('user')
            ->where('rfid_tag', $rfidTag)
            ->first();
    }

    /**
     * Soft delete a card: flag it as deleted (is_deleted = true)
     * instead of permanently removing the record.
     *
     * WRITE → 192.168.214.163 (UPDATE dikirim ke master)
     */
    public function delete($id): bool
    {
        return $this->findOrFail($id)->softDelete();
    }

    /**
     * Restore a previously soft-deleted card.
     *
     * WRITE → 192.168.214.163
     */
    public function restore($id): bool
    {
        return $this->model->newQuery()
            ->withDeleted()
            ->findOrFail($id)
            ->restore();
    }

    /**
     * Count non-deleted cards owned by the given users, optionally
     * excluding a specific card (used when editing an existing card).
     *
     * @param  array<int, int|string>  $userIds
     */
    public function countActiveForUsers(array $userIds, $ignoreId = null): int
    {
        if (empty($userIds)) {
            return 0;
        }

        return $this->model->newQuery()
            ->whereIn('user_id', $userIds)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->count();
    }

    /**
     * Count cards grouped by status.
     */
    public function countByStatus(): array
    {
        return $this->model->newQuery()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }

    /**
     * Active cards whose validity end (valid_until) has already passed.
     *
     * The grace period is evaluated afterwards in PHP (via the model) so this
     * query stays database-agnostic (no vendor-specific date arithmetic).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Kartu>
     */
    public function activeExpiredCandidates()
    {
        return $this->model->newQuery()
            ->with('user')
            ->where('status', Kartu::STATUS_AKTIF)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now())
            ->get();
    }
}
