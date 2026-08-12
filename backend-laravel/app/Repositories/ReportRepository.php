<?php

namespace App\Repositories;

use App\Models\Card;
use App\Models\Kartu;
use App\Models\LogCctv;
use App\Models\LogRfidScan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Data access for transaction (card access log) reports.
 *
 * Datanya diambil dari tabel `log_rfid_scan` (koneksi `pgsql_replica`) dengan
 * pemetaan kolom sebagai berikut agar payload report tetap kompatibel dengan
 * frontend `ReportKartu.vue`:
 *
 *   log_rfid_scan.event_ts  → tapped_at
 *   log_rfid_scan.uid       → card_number
 *   log_rfid_scan.gate_id   → gate, dan direction diturunkan dari prefiks
 *                             (GATE_IN_*  → 1 / Tab In,
 *                              GATE_OUT_* → 2 / Tab Out)
 *   log_rfid_scan.result    → access_granted (result = 'ALLOW')
 *   log_rfid_scan.cctv_id   → snapshot CCTV (relasi ke log_cctv)
 */
class ReportRepository extends BaseRepository
{
    /** Hard row cap for the detail listing to prevent PHP OOM on yearly reports. */
    public const MAX_DETAIL_ROWS = 10_000;

    /** Result value considered as "granted / diterima". */
    public const RESULT_ALLOW = 'ALLOW';

    /**
     * SQL expression that derives an integer direction (1/2) from gate_id.
     * 1 = Tab In, 2 = Tab Out, 0 = tidak diketahui.
     */
    protected const DIRECTION_EXPR = "CASE
            WHEN gate_id ILIKE 'GATE_IN%%'  THEN 1
            WHEN gate_id ILIKE 'GATE_OUT%%' THEN 2
            ELSE 0
        END";

    public function __construct(LogRfidScan $model)
    {
        parent::__construct($model);
    }

    /**
     * Base query for a date range with optional report filters applied.
     */
    public function rangeQuery(Carbon $from, Carbon $to, array $filters = []): Builder
    {
        return $this->model->newQuery()
            ->whereBetween('event_ts', [$from, $to])
            ->when($filters['direction'] ?? null, function ($query, $direction) {
                $prefix = ((int) $direction === 2) ? 'GATE_OUT%' : 'GATE_IN%';
                $query->where('gate_id', 'ilike', $prefix);
            })
            ->when(
                isset($filters['access_granted'])
                    && $filters['access_granted'] !== ''
                    && $filters['access_granted'] !== null,
                function ($query) use ($filters) {
                    $granted = filter_var($filters['access_granted'], FILTER_VALIDATE_BOOLEAN);
                    if ($granted) {
                        $query->where('result', self::RESULT_ALLOW);
                    } else {
                        $query->where(function ($q) {
                            $q->whereNull('result')
                                ->orWhere('result', '<>', self::RESULT_ALLOW);
                        });
                    }
                }
            )
            ->when($filters['gate'] ?? null, fn ($q, $gate) => $q->where('gate_id', 'ilike', "%{$gate}%"))
            ->when($filters['card_number'] ?? null, fn ($q, $card) => $q->where('uid', 'ilike', "%{$card}%"))
            ->when($filters['uid'] ?? null, fn ($q, $uid) => $q->where('uid', 'ilike', "%{$uid}%"));
        // Catatan: filter `no_plat` dan `user_id` tidak diterapkan di sini
        // karena tabel log_rfid_scan tidak memiliki kolomnya. Filter tersebut
        // hanya bekerja pada report berbasis kartu_access_logs.
    }

    // -------------------------------------------------------------------------
    // SQL-based aggregate methods (zero PHP row allocation)
    // -------------------------------------------------------------------------

    /**
     * Headline metrics in a single SQL query — no PHP row data.
     */
    public function summarySQL(Carbon $from, Carbon $to, array $filters = []): array
    {
        $direction = self::DIRECTION_EXPR;
        $allow     = self::RESULT_ALLOW;

        $row = $this->rangeQuery($from, $to, $filters)
            ->selectRaw("
                COUNT(*)                                                                       AS total,
                SUM(CASE WHEN result = ?                    THEN 1 ELSE 0 END)                AS granted,
                SUM(CASE WHEN ({$direction}) = 1            THEN 1 ELSE 0 END)                AS tab_in,
                SUM(CASE WHEN ({$direction}) = 2            THEN 1 ELSE 0 END)                AS tab_out,
                COUNT(DISTINCT CASE WHEN uid IS NOT NULL THEN uid END)                        AS unique_cards
            ", [$allow])
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
            'unique_users' => 0, // tidak tersedia dari log_rfid_scan.
        ];
    }

    /**
     * Time-series tap counts via SQL GROUP BY — keyed by bucket integer.
     *
     * @param  bool  $custom  Jika true, kunci adalah offset serial sejak $from
     *                       (jam ke-N / hari ke-N / bulan ke-N) sehingga rentang
     *                       yang lintas hari/bulan tidak bertabrakan.
     * @return array<int, array{total:int,in:int,out:int,granted:int,denied:int}>
     */
    public function timelineSQL(Carbon $from, Carbon $to, array $filters = [], string $bucket = 'day', bool $custom = false): array
    {
        if ($custom) {
            $fromLit = "TIMESTAMP '" . $from->format('Y-m-d H:i:s') . "'";
            switch ($bucket) {
                case 'hour':
                    $expr = "FLOOR(EXTRACT(EPOCH FROM (event_ts - {$fromLit})) / 3600)::integer";
                    break;
                case 'month':
                    $expr = "((EXTRACT(YEAR FROM event_ts) - EXTRACT(YEAR FROM {$fromLit})) * 12
                              + (EXTRACT(MONTH FROM event_ts) - EXTRACT(MONTH FROM {$fromLit})))::integer";
                    break;
                default: // day
                    $expr = "FLOOR(EXTRACT(EPOCH FROM (event_ts::date - {$fromLit}::date)))::integer";
                    // event_ts::date - from::date menghasilkan integer (day diff) di Postgres,
                    // tapi kita bungkus dengan EPOCH untuk konsisten dengan hour/month expr.
                    // Sebenarnya cukup: EXTRACT(DAY FROM (event_ts::date - from::date))
                    $expr = "(event_ts::date - {$fromLit}::date)::integer";
            }
        } else {
            switch ($bucket) {
                case 'hour':  $expr = 'EXTRACT(HOUR  FROM event_ts)::integer'; break;
                case 'month': $expr = 'EXTRACT(MONTH FROM event_ts)::integer'; break;
                default:      $expr = 'EXTRACT(DAY   FROM event_ts)::integer';
            }
        }

        $direction = self::DIRECTION_EXPR;
        $allow     = self::RESULT_ALLOW;

        return $this->rangeQuery($from, $to, $filters)
            ->selectRaw("
                {$expr}                                                            AS bucket_key,
                COUNT(*)                                                           AS total,
                SUM(CASE WHEN result = ?             THEN 1 ELSE 0 END)            AS granted,
                SUM(CASE WHEN ({$direction}) = 1     THEN 1 ELSE 0 END)            AS tab_in,
                SUM(CASE WHEN ({$direction}) = 2     THEN 1 ELSE 0 END)            AS tab_out
            ", [$allow])
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
     * Breakdown per nilai `result` (menggantikan reason breakdown yang tidak
     * tersedia di log_rfid_scan).
     *
     * @return array<int, array{code:string,label:string,total:int,granted:int,denied:int}>
     */
    public function reasonBreakdownSQL(Carbon $from, Carbon $to, array $filters = []): array
    {
        $allow = self::RESULT_ALLOW;

        return $this->rangeQuery($from, $to, $filters)
            ->selectRaw("
                COALESCE(NULLIF(result, ''), 'UNKNOWN')                                AS reason,
                COUNT(*)                                                               AS total,
                SUM(CASE WHEN result = ? THEN 1 ELSE 0 END)                            AS granted
            ", [$allow])
            ->groupByRaw("COALESCE(NULLIF(result, ''), 'UNKNOWN')")
            ->orderByRaw('total DESC')
            ->get()
            ->map(fn ($r) => [
                'code'    => $r->reason,
                'label'   => $this->resultLabel((string) $r->reason),
                'total'   => (int) $r->total,
                'granted' => (int) $r->granted,
                'denied'  => (int) $r->total - (int) $r->granted,
            ])
            ->values()
            ->all();
    }

    /**
     * Tap counts grouped by gate_id via SQL GROUP BY.
     *
     * @return array<int, array{gate:string,total:int,in:int,out:int}>
     */
    public function gateBreakdownSQL(Carbon $from, Carbon $to, array $filters = []): array
    {
        $direction = self::DIRECTION_EXPR;

        return $this->rangeQuery($from, $to, $filters)
            ->selectRaw("
                COALESCE(gate_id, 'Tidak diketahui')                                   AS gate,
                COUNT(*)                                                               AS total,
                SUM(CASE WHEN ({$direction}) = 1 THEN 1 ELSE 0 END)                    AS tab_in,
                SUM(CASE WHEN ({$direction}) = 2 THEN 1 ELSE 0 END)                    AS tab_out
            ")
            ->groupByRaw("COALESCE(gate_id, 'Tidak diketahui')")
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
     * Full rows for the detail listing, hydrated with card / kartu / cctv.
     *
     * @return array{rows: Collection, total: int, truncated: bool}
     */
    public function detailRows(Carbon $from, Carbon $to, array $filters = []): array
    {
        $query = $this->rangeQuery($from, $to, $filters)->orderBy('event_ts');

        $total     = (clone $query)->count();
        $truncated = $total > self::MAX_DETAIL_ROWS;
        $rows      = $query->limit(self::MAX_DETAIL_ROWS)->get();

        $this->hydrateRelations($rows);

        return compact('rows', 'total', 'truncated');
    }

    /**
     * Hydrate `kartu.user` (via uid → kartus.rfid_tag) dan `cctv` (via cctv_id)
     * pada koleksi log_rfid_scan. Dilakukan manual karena tabel-tabel tersebut
     * berada pada koneksi database yang berbeda.
     *
     * @param Collection<int, LogRfidScan> $rows
     */
    protected function hydrateRelations(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        // Peta uid → Kartu (dengan pemilik). Menggunakan koneksi `pgsql`.
        $uids = $rows->pluck('uid')->filter()->unique()->values()->all();
        $kartuByUid = ! empty($uids)
            ? Kartu::query()->with('user')->whereIn('rfid_tag', $uids)->get()->keyBy('rfid_tag')
            : collect();

        // Peta uid → Card (untuk fallback nama/unit dari tabel cards di replica).
        $cardByUid = ! empty($uids)
            ? Card::query()->whereIn('uid', $uids)->get()->keyBy('uid')
            : collect();

        // Peta cctv_id → LogCctv.
        $cctvIds = $rows->pluck('cctv_id')->filter()->unique()->values()->all();
        $cctvById = ! empty($cctvIds)
            ? LogCctv::query()->whereIn('id', $cctvIds)->get()->keyBy('id')
            : collect();

        foreach ($rows as $row) {
            if ($row->uid && $kartuByUid->has($row->uid)) {
                $row->setRelation('kartu', $kartuByUid->get($row->uid));
            }
            if ($row->uid && $cardByUid->has($row->uid)) {
                $row->setRelation('card', $cardByUid->get($row->uid));
            }
            if ($row->cctv_id && $cctvById->has($row->cctv_id)) {
                $row->setRelation('cctv', $cctvById->get($row->cctv_id));
            }
        }
    }

    /**
     * Human-friendly label for a raw `result` value.
     */
    protected function resultLabel(string $code): string
    {
        $normalized = strtoupper($code);
        return match ($normalized) {
            'ALLOW'   => 'Diterima',
            'DENY'    => 'Ditolak',
            'UNKNOWN' => 'Tidak diketahui',
            default   => ucfirst(strtolower(str_replace('_', ' ', $code))),
        };
    }
}
