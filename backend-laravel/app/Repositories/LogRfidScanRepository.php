<?php

namespace App\Repositories;

use App\Models\LogRfidScan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class LogRfidScanRepository extends BaseRepository
{
    public function __construct(LogRfidScan $model)
    {
        parent::__construct($model);
    }

    /**
     * Paginated, filterable list of RFID scan logs.
     *
     * Supported filters:
     *   - gate_id  : exact match on gate identifier (GATE_IN_01, GATE_OUT_01, ...)
     *   - uid      : exact match on RFID UID
     *   - result   : exact match on gate decision (ALLOW / DENY / ...)
     *   - date_from: inclusive lower bound on event_ts (Y-m-d or datetime)
     *   - date_to  : inclusive upper bound on event_ts (Y-m-d or datetime)
     *   - search   : partial match on uid / gate_id / result
     *
     * @param array<string, mixed> $filters
     */
    public function filter(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if (! empty($filters['gate_id'])) {
            $query->where('gate_id', $filters['gate_id']);
        }

        if (! empty($filters['uid'])) {
            $query->where('uid', $filters['uid']);
        }

        if (! empty($filters['result'])) {
            $query->where('result', $filters['result']);
        }

        if (! empty($filters['cctv_id'])) {
            $query->where('cctv_id', $filters['cctv_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('event_ts', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('event_ts', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('uid', 'ilike', $term)
                    ->orWhere('gate_id', 'ilike', $term)
                    ->orWhere('result', 'ilike', $term);
            });
        }

        return $query->orderBy('event_ts', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * Scan history for a specific gate, most recent first.
     */
    public function historyByGate(string $gateId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('gate_id', $gateId)
            ->orderBy('event_ts', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * Scan history for a specific card UID, most recent first.
     */
    public function historyByUid(string $uid, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('uid', $uid)
            ->orderBy('event_ts', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * Most recent N scans across all gates.
     *
     * @return Collection<int, LogRfidScan>
     */
    public function latest(int $limit = 20): Collection
    {
        return $this->model->newQuery()
            ->orderBy('event_ts', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Latest scan per gate — useful for a live status dashboard.
     *
     * @return Collection<int, LogRfidScan>
     */
    public function latestPerGate(): Collection
    {
        $latest = $this->model->newQuery()
            ->selectRaw('gate_id, MAX(event_ts) as max_event_ts')
            ->groupBy('gate_id');

        return $this->model->newQuery()
            ->joinSub($latest, 'latest', function ($join) {
                $join->on('log_rfid_scan.gate_id', '=', 'latest.gate_id')
                    ->on('log_rfid_scan.event_ts', '=', 'latest.max_event_ts');
            })
            ->orderBy('log_rfid_scan.gate_id')
            ->get($this->model->getTable() . '.*');
    }

    /**
     * Aggregate counters over a date range, grouped by result.
     *
     * @return array<string, int>
     */
    public function countsByResult(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = $this->model->newQuery()
            ->selectRaw('result, COUNT(*) as total')
            ->groupBy('result');

        if ($dateFrom) {
            $query->where('event_ts', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('event_ts', '<=', $dateTo);
        }

        return $query->pluck('total', 'result')->map(fn ($v) => (int) $v)->all();
    }
}
