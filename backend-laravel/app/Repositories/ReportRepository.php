<?php

namespace App\Repositories;

use App\Models\Kartu;
use App\Models\KartuAccessLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Data access for transaction (card access log) reports.
 *
 * Heavy aggregations (recap) are now pushed to the database via SQL GROUP BY,
 * so yearly reports no longer load thousands of rows into PHP memory.
 * Detail listings are capped at MAX_DETAIL_ROWS to prevent memory spikes.
 */
class ReportRepository extends BaseRepository
{
    /** Hard row cap for the detail listing to prevent PHP OOM on yearly reports. */
    public const MAX_DETAIL_ROWS = 10_000;

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

    // -------------------------------------------------------------------------
    // SQL-based aggregate methods (zero PHP row allocation)
    // -------------------------------------------------------------------------

    /**
     * Headline metrics in a single SQL query — no PHP row data.
     */
    public function summarySQL(Carbon $from, Carbon $to, array $filters = []): array
    {
        $row = $this->rangeQuery($from, $to, $filters)
            ->selectRaw('
                COUNT(*)                                                                  AS total,
                SUM(CASE WHEN access_granted IS TRUE  THEN 1 ELSE 0 END)                 AS granted,
                SUM(CASE WHEN direction = ?           THEN 1 ELSE 0 END)                 AS tab_in,
                SUM(CASE WHEN direction = ?           THEN 1 ELSE 0 END)                 AS tab_out,
                COUNT(DISTINCT CASE WHEN card_number IS NOT NULL THEN card_number END)    AS unique_cards,
                COUNT(DISTINCT CASE WHEN user_id     IS NOT NULL THEN user_id     END)    AS unique_users
            ', [KartuAccessLog::DIRECTION_IN, KartuAccessLog::DIRECTION_OUT])
            ->first();

        $total   = (int) ($row->total   ?? 0);
        $granted = (int) ($row->granted ?? 0);

        return [
            'total'        => $total,
            'tab_in'       => (int) ($row->tab_in       ?? 0),
            'tab_out'      => (int) ($row->tab_out      ?? 0),
            'granted'      => $granted,
            'denied'       => $total - $granted,
            'grant_rate'   => $total > 0 ? round($granted / $total * 100, 1) : 0.0,
            'unique_cards' => (int) ($row->unique_cards ?? 0),
            'unique_users' => (int) ($row->unique_users ?? 0),
        ];
    }

    /**
     * Time-series tap counts via SQL GROUP BY — keyed by bucket integer.
     *
     * @return array<int, array{total:int,in:int,out:int,granted:int,denied:int}>
     */
    public function timelineSQL(Carbon $from, Carbon $to, array $filters = [], string $bucket = 'day'): array
    {
        switch ($bucket) {
            case 'hour':  $expr = 'EXTRACT(HOUR  FROM tapped_at)::integer'; break;
            case 'month': $expr = 'EXTRACT(MONTH FROM tapped_at)::integer'; break;
            default:      $expr = 'EXTRACT(DAY   FROM tapped_at)::integer';
        }

        return $this->rangeQuery($from, $to, $filters)
            ->selectRaw("
                {$expr}                                                               AS bucket_key,
                COUNT(*)                                                              AS total,
                SUM(CASE WHEN access_granted IS TRUE THEN 1 ELSE 0 END)              AS granted,
                SUM(CASE WHEN direction = ?          THEN 1 ELSE 0 END)              AS tab_in,
                SUM(CASE WHEN direction = ?          THEN 1 ELSE 0 END)              AS tab_out
            ", [KartuAccessLog::DIRECTION_IN, KartuAccessLog::DIRECTION_OUT])
            ->groupByRaw($expr)
            ->get()
            ->keyBy('bucket_key')
            ->map(fn ($r) => [
                'total'   => (int) $r->total,
                'granted' => (int) $r->granted,
                'denied'  => (int) $r->total - (int) $r->granted,
                'in'      => (int) $r->tab_in,
                'out'     => (int) $r->tab_out,
            ])
            ->toArray();
    }

    /**
     * Access-denied reasons grouped by reason code via SQL GROUP BY.
     *
     * @return array<int, array{code:string,label:string,total:int,granted:int,denied:int}>
     */
    public function reasonBreakdownSQL(Carbon $from, Carbon $to, array $filters = []): array
    {
        return $this->rangeQuery($from, $to, $filters)
            ->selectRaw("
                COALESCE(reason, 'unknown_card')                                      AS reason,
                COUNT(*)                                                              AS total,
                SUM(CASE WHEN access_granted IS TRUE THEN 1 ELSE 0 END)              AS granted
            ")
            ->groupByRaw("COALESCE(reason, 'unknown_card')")
            ->orderByRaw('total DESC')
            ->get()
            ->map(fn ($r) => [
                'code'    => $r->reason,
                'label'   => Kartu::REASON_MESSAGES[$r->reason] ?? ucfirst(str_replace('_', ' ', (string) $r->reason)),
                'total'   => (int) $r->total,
                'granted' => (int) $r->granted,
                'denied'  => (int) $r->total - (int) $r->granted,
            ])
            ->values()
            ->all();
    }

    /**
     * Tap counts grouped by gate / device via SQL GROUP BY.
     *
     * @return array<int, array{gate:string,total:int,in:int,out:int}>
     */
    public function gateBreakdownSQL(Carbon $from, Carbon $to, array $filters = []): array
    {
        return $this->rangeQuery($from, $to, $filters)
            ->selectRaw("
                COALESCE(gate, 'Tidak diketahui')                                     AS gate,
                COUNT(*)                                                              AS total,
                SUM(CASE WHEN direction = ? THEN 1 ELSE 0 END)                       AS tab_in,
                SUM(CASE WHEN direction = ? THEN 1 ELSE 0 END)                       AS tab_out
            ", [KartuAccessLog::DIRECTION_IN, KartuAccessLog::DIRECTION_OUT])
            ->groupByRaw("COALESCE(gate, 'Tidak diketahui')")
            ->orderByRaw('total DESC')
            ->get()
            ->map(fn ($r) => [
                'gate'  => $r->gate,
                'total' => (int) $r->total,
                'in'    => (int) $r->tab_in,
                'out'   => (int) $r->tab_out,
            ])
            ->values()
            ->all();
    }

    // -------------------------------------------------------------------------
    // Row-fetching methods (used for detail listing and Excel/PDF export)
    // -------------------------------------------------------------------------

    /**
     * Full rows with owner relations for the detail listing.
     * Capped at MAX_DETAIL_ROWS to prevent PHP OOM on large date ranges.
     *
     * @return array{rows: Collection, total: int, truncated: bool}
     */
    public function detailRows(Carbon $from, Carbon $to, array $filters = []): array
    {
        $query = $this->rangeQuery($from, $to, $filters)
            ->with(['kartu.user', 'user'])
            ->orderBy('tapped_at');

        $total     = (clone $query)->count();
        $truncated = $total > self::MAX_DETAIL_ROWS;
        $rows      = $query->limit(self::MAX_DETAIL_ROWS)->get();

        return compact('rows', 'total', 'truncated');
    }
}
