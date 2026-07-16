<?php

namespace App\Repositories;

use App\Models\LogRfidConn;
use Illuminate\Database\Eloquent\Collection;

class LogRfidConnRepository extends BaseRepository
{
    public function __construct(LogRfidConn $model)
    {
        parent::__construct($model);
    }

    /**
     * Latest connection status for every gate.
     *
     * Selects, per gate_id, the most recent row (by event_ts) so the dashboard
     * can show the current RFID reader state without scanning the full log.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\LogRfidConn>
     */
    public function latestPerGate(): Collection
    {
        $latest = $this->model->newQuery()
            ->selectRaw('gate_id, MAX(event_ts) as max_event_ts')
            ->groupBy('gate_id');

        return $this->model->newQuery()
            ->joinSub($latest, 'latest', function ($join) {
                $join->on('log_rfid_conn.gate_id', '=', 'latest.gate_id')
                    ->on('log_rfid_conn.event_ts', '=', 'latest.max_event_ts');
            })
            ->orderBy('log_rfid_conn.gate_id')
            ->get($this->model->getTable() . '.*');
    }

    /**
     * Connection history for a specific gate.
     *
     * Returns paginated log entries for the given gate_id, ordered by most recent first.
     *
     * @param string $gateId
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function historyByGate(string $gateId, int $perPage = 20)
    {
        return $this->model->newQuery()
            ->where('gate_id', $gateId)
            ->orderBy('event_ts', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }
}
