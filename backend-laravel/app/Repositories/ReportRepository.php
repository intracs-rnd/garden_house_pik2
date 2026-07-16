<?php

namespace App\Repositories;

use App\Models\KartuAccessLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Data access for transaction (card access log) reports.
 *
 * Reports are always scoped to a bounded date range (a single day, month or
 * year), so it is cheap to pull the rows and aggregate them in PHP. This keeps
 * the queries database-agnostic (no MySQL/Postgres specific date functions).
 */
class ReportRepository extends BaseRepository
{
    public function __construct(KartuAccessLog $model)
    {
        parent::__construct($model);
    }

    /**
     * Base query for a date range with optional report filters applied.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function rangeQuery(Carbon $from, Carbon $to, array $filters = [])
    {
        return $this->model->newQuery()
            ->whereBetween('tapped_at', [$from, $to])
            ->when($filters['direction'] ?? null, fn ($query, $direction) => $query->where('direction', $direction))
            ->when(isset($filters['access_granted']) && $filters['access_granted'] !== '' && $filters['access_granted'] !== null, function ($query) use ($filters) {
                $query->where('access_granted', filter_var($filters['access_granted'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when($filters['gate'] ?? null, fn ($query, $gate) => $query->where('gate', 'ilike', "%{$gate}%"))
            ->when($filters['no_plat'] ?? null, fn ($query, $plat) => $query->where('no_plat', 'ilike', "%{$plat}%"))
            ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($filters['card_number'] ?? null, fn ($query, $card) => $query->where('card_number', 'ilike', "%{$card}%"));
    }

    /**
     * Lightweight rows used to build the recap (aggregations only).
     */
    public function recapRows(Carbon $from, Carbon $to, array $filters = []): Collection
    {
        return $this->rangeQuery($from, $to, $filters)
            ->orderBy('tapped_at')
            ->get(['id', 'user_id', 'card_number', 'direction', 'access_granted', 'reason', 'gate', 'tapped_at']);
    }

    /**
     * Full rows (with owner relations) used to build the detail listing.
     */
    public function detailRows(Carbon $from, Carbon $to, array $filters = []): Collection
    {
        return $this->rangeQuery($from, $to, $filters)
            ->with(['kartu.user', 'user'])
            ->orderBy('tapped_at')
            ->get();
    }
}
