<?php

namespace App\Repositories;

use App\Models\KartuAccessLog;

class KartuAccessLogRepository extends BaseRepository
{
    public function __construct(KartuAccessLog $model)
    {
        parent::__construct($model);
    }

    /**
     * Paginate access logs with optional filters.
     */
    public function filter(array $filters = [], int $perPage = 15)
    {
        return $this->model->newQuery()
            ->with(['kartu.user', 'user'])
            ->when($filters['kartu_id'] ?? null, fn ($query, $kartuId) => $query->where('kartu_id', $kartuId))
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($filters['card_number'] ?? null, fn ($query, $cardNumber) => $query->where('card_number', 'ilike', "%{$cardNumber}%"))
            ->when($filters['direction'] ?? null, fn ($query, $direction) => $query->where('direction', $direction))
            ->when(isset($filters['access_granted']) && $filters['access_granted'] !== '', function ($query) use ($filters) {
                $query->where('access_granted', filter_var($filters['access_granted'], FILTER_VALIDATE_BOOLEAN));
            })
            ->latest('tapped_at')
            ->paginate($perPage);
    }
}
