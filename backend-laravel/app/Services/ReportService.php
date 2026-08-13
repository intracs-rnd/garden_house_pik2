<?php

namespace App\Services;

use App\Models\Kartu;
use App\Models\KartuAccessLog;
use App\Models\LogRfidScan;
use App\Repositories\ReportRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds recap (aggregated) and detail transaction reports from the card
 * access logs, grouped by day (harian), month (bulanan) or year (tahunan).
 */
class ReportService
{
    /** Supported reporting periods. */
    public const PERIODS = ['harian', 'bulanan', 'tahunan', 'kustom'];

    /** Indonesian short month names (index 1..12). */
    protected const SHORT_MONTHS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    protected ReportRepository $reports;

    public function __construct(ReportRepository $reports)
    {
        $this->reports = $reports;
    }

    /**
     * Build the recap (aggregated) report payload.
     * All aggregations are pushed to SQL — no rows are loaded into PHP memory.
     */
    public function recap(string $period, ?string $date, array $filters = []): array
    {
        $range = $this->applyTimeWindow($this->resolveRange($period, $date, $filters), $filters);

        return [
            'type'         => 'rekap',
            'period'       => $range['period'],
            'period_label' => $this->periodLabel($range['period']),
            'range'        => $this->rangeMeta($range),
            'filters'      => $this->filterMeta($filters),
            'summary'      => $this->reports->summarySQL($range['from'], $range['to'], $filters),
            'timeline'     => $this->buildTimelineSQL($range, $filters),
            'by_reason'    => $this->reports->reasonBreakdownSQL($range['from'], $range['to'], $filters),
            'by_gate'      => $this->reports->gateBreakdownSQL($range['from'], $range['to'], $filters),
            'generated_at' => $this->humanNow(),
        ];
    }

    /**
     * Build the detail (per-tap) report payload.
     * Summary uses SQL aggregation; rows are capped at MAX_DETAIL_ROWS.
     */
    public function detail(string $period, ?string $date, array $filters = []): array
    {
        $range  = $this->applyTimeWindow($this->resolveRange($period, $date, $filters), $filters);
        $result = $this->reports->detailRows($range['from'], $range['to'], $filters);
        $rows   = $result['rows'];

        return [
            'type'          => 'detail',
            'period'        => $range['period'],
            'period_label'  => $this->periodLabel($range['period']),
            'range'         => $this->rangeMeta($range),
            'filters'       => $this->filterMeta($filters),
            'summary'       => $this->reports->summarySQL($range['from'], $range['to'], $filters),
            'total_records' => $result['total'],
            'truncated'     => $result['truncated'],
            'rows'          => $rows->values()->map(function ($row, $index) {
                return $this->mapDetailRow($row, $index + 1);
            })->all(),
            'generated_at'  => $this->humanNow(),
        ];
    }

    /**
     * Build the timeline array from SQL aggregation data.
     * Fills all bucket slots (hours/days/months) including empty ones with zero.
     */
    protected function buildTimelineSQL(array $range, array $filters): array
    {
        $keys    = $this->bucketKeys($range);
        $isCustom = ($range['period'] ?? '') === 'kustom';
        $grouped = $this->reports->timelineSQL(
            $range['from'],
            $range['to'],
            $filters,
            $range['bucket'],
            $isCustom
        );

        return collect($keys)->map(function ($key) use ($grouped, $range) {
            $bucket = $grouped[$key] ?? ['total' => 0, 'in' => 0, 'out' => 0, 'granted' => 0, 'denied' => 0];
            return [
                'label'   => $this->bucketLabel((int) $key, $range['bucket'], $range),
                'total'   => $bucket['total'],
                'in'      => $bucket['in'],
                'out'     => $bucket['out'],
                'granted' => $bucket['granted'],
                'denied'  => $bucket['denied'],
            ];
        })->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Range resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the [from, to] window plus bucketing metadata for a period.
     *
     * When `period === 'kustom'` (atau saat `filters.date_from` & `filters.date_to`
     * dikirim tanpa periode standar), rentang diambil bebas dari filter tersebut
     * dan bucket dipilih otomatis (hour / day / month) sesuai lebar rentang.
     *
     * @return array{period:string, from:Carbon, to:Carbon, label:string, bucket:string}
     */
    public function resolveRange(string $period, ?string $date, array $filters = []): array
    {
        // Rentang bebas ("kustom") — dipicu oleh periode "kustom" atau ketika
        // date_from + date_to sama-sama dikirim di filters.
        $hasCustomRange = ($period === 'kustom')
            || (! empty($filters['date_from']) && ! empty($filters['date_to']));

        if ($hasCustomRange) {
            return $this->resolveCustomRange(
                $filters['date_from'] ?? $date,
                $filters['date_to']   ?? $date
            );
        }

        $period = in_array($period, self::PERIODS, true) ? $period : 'bulanan';
        $anchor = $this->parseAnchor($period, $date);

        switch ($period) {
            case 'harian':
                return [
                    'period' => 'harian',
                    'from'   => $anchor->copy()->startOfDay(),
                    'to'     => $anchor->copy()->endOfDay(),
                    'label'  => $anchor->locale('id')->isoFormat('dddd, D MMMM Y'),
                    'bucket' => 'hour',
                ];

            case 'tahunan':
                return [
                    'period' => 'tahunan',
                    'from'   => $anchor->copy()->startOfYear(),
                    'to'     => $anchor->copy()->endOfYear(),
                    'label'  => $anchor->format('Y'),
                    'bucket' => 'month',
                ];

            case 'bulanan':
            default:
                return [
                    'period' => 'bulanan',
                    'from'   => $anchor->copy()->startOfMonth(),
                    'to'     => $anchor->copy()->endOfMonth(),
                    'label'  => $anchor->locale('id')->isoFormat('MMMM Y'),
                    'bucket' => 'day',
                ];
        }
    }

    /**
     * Rentang bebas: `from` → start-of-day, `to` → end-of-day.
     * Bucket dipilih otomatis:
     *   - <= 2 hari  → 'hour'
     *   - <= 62 hari → 'day'
     *   - lebih     → 'month'
     */
    protected function resolveCustomRange(?string $fromRaw, ?string $toRaw): array
    {
        $from = $this->parseDateLoose($fromRaw) ?: Carbon::now()->startOfDay();
        $to   = $this->parseDateLoose($toRaw)   ?: Carbon::now()->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $from = $from->copy()->startOfDay();
        $to   = $to->copy()->endOfDay();

        $days = $from->diffInDays($to) + 1;
        if     ($days <= 2)  $bucket = 'hour';
        elseif ($days <= 62) $bucket = 'day';
        else                 $bucket = 'month';

        $label = $from->locale('id')->isoFormat('D MMM Y')
               . ' – '
               . $to->locale('id')->isoFormat('D MMM Y');

        return [
            'period' => 'kustom',
            'from'   => $from,
            'to'     => $to,
            'label'  => $label,
            'bucket' => $bucket,
        ];
    }

    /**
     * Parse a date/datetime string, returning null on failure.
     */
    protected function parseDateLoose(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Narrow a daily range to an optional [time_from, time_to] window.
     *
     * Only applies to the "harian" period; other periods are returned as-is.
     */
    protected function applyTimeWindow(array $range, array $filters): array
    {
        if ($range['period'] !== 'harian') {
            return $range;
        }

        $day = $range['from']->copy()->startOfDay();

        if (! empty($filters['time_from']) && preg_match('/^(\d{1,2}):(\d{2})$/', $filters['time_from'], $m)) {
            $range['from'] = $day->copy()->setTime((int) $m[1], (int) $m[2], 0);
        }

        if (! empty($filters['time_to']) && preg_match('/^(\d{1,2}):(\d{2})$/', $filters['time_to'], $m)) {
            $range['to'] = $day->copy()->setTime((int) $m[1], (int) $m[2], 59);
        }

        return $range;
    }

    /**
     * Parse the incoming date string tolerantly for each period.
     */
    protected function parseAnchor(string $period, ?string $date): Carbon
    {
        $now = Carbon::now();

        if (! $date) {
            return $now;
        }

        try {
            if ($period === 'tahunan' && preg_match('/^\d{4}$/', $date)) {
                return Carbon::createFromDate((int) $date, 1, 1)->startOfDay();
            }

            if ($period === 'bulanan' && preg_match('/^\d{4}-\d{2}$/', $date)) {
                return Carbon::createFromFormat('Y-m-d', $date . '-01')->startOfDay();
            }

            return Carbon::parse($date);
        } catch (\Throwable $e) {
            return $now;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Aggregations
    |--------------------------------------------------------------------------
    */

    /**
     * Headline metrics shared by recap and detail reports.
     */
    protected function summarize(Collection $rows): array
    {
        $total   = $rows->count();
        $granted = $rows->where('access_granted', true)->count();

        return [
            'total'        => $total,
            'tab_in'       => $rows->where('direction', KartuAccessLog::DIRECTION_IN)->count(),
            'tab_out'      => $rows->where('direction', KartuAccessLog::DIRECTION_OUT)->count(),
            'granted'      => $granted,
            'denied'       => $total - $granted,
            'grant_rate'   => $total > 0 ? round($granted / $total * 100, 1) : 0.0,
            'unique_cards' => $rows->pluck('card_number')->filter()->unique()->count(),
            'unique_users' => $rows->pluck('user_id')->filter()->unique()->count(),
        ];
    }

    /**
     * Time-series counts across the period's buckets (hour/day/month).
     */
    protected function timeline(Collection $rows, array $range): array
    {
        $keys = $this->bucketKeys($range);

        $grouped = $rows->groupBy(function ($row) use ($range) {
            return $this->bucketKey($row->tapped_at, $range['bucket']);
        });

        return collect($keys)->map(function ($key) use ($grouped, $range) {
            /** @var Collection $bucket */
            $bucket  = $grouped->get($key, collect());
            $total   = $bucket->count();
            $granted = $bucket->where('access_granted', true)->count();

            return [
                'label'   => $this->bucketLabel($key, $range['bucket']),
                'total'   => $total,
                'in'      => $bucket->where('direction', KartuAccessLog::DIRECTION_IN)->count(),
                'out'     => $bucket->where('direction', KartuAccessLog::DIRECTION_OUT)->count(),
                'granted' => $granted,
                'denied'  => $total - $granted,
            ];
        })->all();
    }

    /**
     * Access decisions grouped by reason code (with human labels).
     */
    protected function reasonBreakdown(Collection $rows): array
    {
        return $rows->groupBy(function ($row) {
            return $row->reason ?: 'unknown_card';
        })->map(function (Collection $group, $reason) {
            return [
                'code'    => $reason,
                'label'   => Kartu::REASON_MESSAGES[$reason] ?? ucfirst(str_replace('_', ' ', (string) $reason)),
                'total'   => $group->count(),
                'granted' => $group->where('access_granted', true)->count(),
                'denied'  => $group->where('access_granted', false)->count(),
            ];
        })->sortByDesc('total')->values()->all();
    }

    /**
     * Tap counts grouped by gate / device.
     */
    protected function gateBreakdown(Collection $rows): array
    {
        return $rows->groupBy(function ($row) {
            return $row->gate ?: 'Tidak diketahui';
        })->map(function (Collection $group, $gate) {
            return [
                'gate'    => $gate,
                'total'   => $group->count(),
                'in'      => $group->where('direction', KartuAccessLog::DIRECTION_IN)->count(),
                'out'     => $group->where('direction', KartuAccessLog::DIRECTION_OUT)->count(),
            ];
        })->sortByDesc('total')->values()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Bucketing helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The ordered set of bucket keys that make up the x-axis for a period.
     *
     * @return array<int, int>
     */
    protected function bucketKeys(array $range): array
    {
        switch ($range['bucket']) {
            case 'hour':
                if (($range['period'] ?? '') === 'kustom') {
                    // Kustom: rentang bisa >1 hari — kunci = jam serial sejak from.
                    $hours = (int) $range['from']->diffInHours($range['to']) + 1;
                    return range(0, max(0, $hours - 1));
                }
                return range(0, 23);
            case 'month':
                if (($range['period'] ?? '') === 'kustom') {
                    // Kustom: bulan serial (index sejak from), memuat >12 bila lintas tahun.
                    $months = (int) $range['from']->copy()->startOfMonth()
                        ->diffInMonths($range['to']->copy()->startOfMonth()) + 1;
                    return range(0, max(0, $months - 1));
                }
                return range(1, 12);
            case 'day':
            default:
                if (($range['period'] ?? '') === 'kustom') {
                    $days = (int) $range['from']->diffInDays($range['to']) + 1;
                    return range(0, max(0, $days - 1));
                }
                return range(1, $range['from']->daysInMonth);
        }
    }

    /**
     * The bucket a tap belongs to, given the period's granularity.
     */
    protected function bucketKey(?Carbon $tappedAt, string $bucket): int
    {
        if (! $tappedAt) {
            return 0;
        }

        switch ($bucket) {
            case 'hour':
                return (int) $tappedAt->format('G'); // 0..23
            case 'month':
                return (int) $tappedAt->format('n'); // 1..12
            case 'day':
            default:
                return (int) $tappedAt->format('j'); // 1..31
        }
    }

    /**
     * Human-readable label for a bucket key.
     */
    protected function bucketLabel(int $key, string $bucket, array $range = []): string
    {
        $isCustom = ($range['period'] ?? '') === 'kustom';

        switch ($bucket) {
            case 'hour':
                if ($isCustom && ! empty($range['from'])) {
                    $ts = $range['from']->copy()->addHours($key);
                    return $ts->locale('id')->isoFormat('D MMM HH:00');
                }
                return sprintf('%02d:00', $key);
            case 'month':
                if ($isCustom && ! empty($range['from'])) {
                    $ts = $range['from']->copy()->startOfMonth()->addMonths($key);
                    return $ts->locale('id')->isoFormat('MMM Y');
                }
                return self::SHORT_MONTHS[$key] ?? (string) $key;
            case 'day':
            default:
                if ($isCustom && ! empty($range['from'])) {
                    $ts = $range['from']->copy()->addDays($key);
                    return $ts->locale('id')->isoFormat('D MMM');
                }
                return sprintf('%02d', $key);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Mapping / meta
    |--------------------------------------------------------------------------
    */

    protected function mapDetailRow($row, int $no): array
    {
        // Baris data adalah LogRfidScan (bukan lagi KartuAccessLog).
        // Field diterjemahkan agar payload tetap kompatibel dengan frontend.
        $tappedAt = $row->event_ts;

        $kartu   = $row->relationLoaded('kartu') ? $row->getRelation('kartu') : null;
        $card    = $row->relationLoaded('card')  ? $row->getRelation('card')  : null;
        $cctv    = $row->relationLoaded('cctv')  ? $row->getRelation('cctv')  : null;

        $owner = optional(optional($kartu)->user)->name
            ?: optional($card)->name
            ?: 'Tidak dikenal';

        $granted   = strtoupper((string) $row->result) === LogRfidScan::RESULT_ALLOW;
        $direction = $this->deriveDirection($row->gate_id);

        $cctvImagePath = $cctv->view_image_path ?? null;

        return [
            'no'              => $no,
            'tapped_at'       => $tappedAt ? $tappedAt->toIso8601String() : null,
            'tapped_at_label' => $tappedAt
                ? $tappedAt->locale('id')->isoFormat('DD MMM Y, HH:mm:ss')
                : '-',
            'card_number'     => optional($kartu)->card_number ?: $row->uid,
            'uid'             => $row->uid,
            'no_plat'         => '-',
            'owner'           => $owner,
            'direction'       => $direction,
            'direction_label' => KartuAccessLog::DIRECTIONS[$direction] ?? '-',
            'access_granted'  => $granted,
            'result'          => $row->result,
            'result_label'    => $granted ? 'Diterima' : 'Ditolak',
            'reason'          => $row->result,
            'reason_label'    => $granted ? 'Diterima' : ($row->result ?: 'Tidak diketahui'),
            'gate'            => $row->gate_id ?: '-',
            'cctv_id'         => $row->cctv_id,
            'cctv_image_path' => $cctvImagePath,
            'cctv'            => $cctv ? [
                'id'              => $cctv->id,
                'cctv'            => $cctv->cctv ?? null,
                'view_image_path' => $cctvImagePath,
                'log_time'        => optional($cctv->log_time ?? null)?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Turunkan direction (1 = Tab In, 2 = Tab Out) dari prefiks gate_id.
     */
    protected function deriveDirection(?string $gateId): int
    {
        $gate = strtoupper((string) $gateId);
        if (str_starts_with($gate, 'GATE_IN')) {
            return KartuAccessLog::DIRECTION_IN;
        }
        if (str_starts_with($gate, 'GATE_OUT')) {
            return KartuAccessLog::DIRECTION_OUT;
        }
        return 0;
    }

    protected function rangeMeta(array $range): array
    {
        return [
            'from'  => $range['from']->toIso8601String(),
            'to'    => $range['to']->toIso8601String(),
            'label' => $range['label'],
        ];
    }

    protected function filterMeta(array $filters): array
    {
        $active = [];

        if (! empty($filters['direction'])) {
            $active[] = 'Arah: ' . (KartuAccessLog::DIRECTIONS[(int) $filters['direction']] ?? '-');
        }

        if (isset($filters['access_granted']) && $filters['access_granted'] !== '' && $filters['access_granted'] !== null) {
            $granted  = filter_var($filters['access_granted'], FILTER_VALIDATE_BOOLEAN);
            $active[] = 'Hasil: ' . ($granted ? 'Diterima' : 'Ditolak');
        }

        if (! empty($filters['gate'])) {
            $active[] = 'Gate: ' . $filters['gate'];
        }

        if (! empty($filters['no_plat'])) {
            $active[] = 'No. Plat: ' . $filters['no_plat'];
        }

        if (! empty($filters['time_from']) || ! empty($filters['time_to'])) {
            $active[] = 'Jam: ' . ($filters['time_from'] ?? '00:00') . ' - ' . ($filters['time_to'] ?? '23:59');
        }

        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $active[] = 'Rentang: ' . ($filters['date_from'] ?? '-') . ' s/d ' . ($filters['date_to'] ?? '-');
        }

        return $active;
    }

    protected function periodLabel(string $period): string
    {
        $labels = [
            'harian'   => 'Harian',
            'bulanan'  => 'Bulanan',
            'tahunan'  => 'Tahunan',
            'kustom'   => 'Kustom',
        ];

        return $labels[$period] ?? ucfirst($period);
    }

    protected function humanNow(): string
    {
        return Carbon::now()->locale('id')->isoFormat('DD MMMM Y, HH:mm');
    }
}
