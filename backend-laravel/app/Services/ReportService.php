<?php

namespace App\Services;

use App\Models\Kartu;
use App\Models\KartuAccessLog;
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
    public const PERIODS = ['harian', 'bulanan', 'tahunan'];

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
     */
    public function recap(string $period, ?string $date, array $filters = []): array
    {
        $range = $this->applyTimeWindow($this->resolveRange($period, $date), $filters);
        $rows  = $this->reports->recapRows($range['from'], $range['to'], $filters);

        return [
            'type'         => 'rekap',
            'period'       => $range['period'],
            'period_label' => $this->periodLabel($range['period']),
            'range'        => $this->rangeMeta($range),
            'filters'      => $this->filterMeta($filters),
            'summary'      => $this->summarize($rows),
            'timeline'     => $this->timeline($rows, $range),
            'by_reason'    => $this->reasonBreakdown($rows),
            'by_gate'      => $this->gateBreakdown($rows),
            'generated_at' => $this->humanNow(),
        ];
    }

    /**
     * Build the detail (per-tap) report payload.
     */
    public function detail(string $period, ?string $date, array $filters = []): array
    {
        $range = $this->applyTimeWindow($this->resolveRange($period, $date), $filters);
        $rows  = $this->reports->detailRows($range['from'], $range['to'], $filters);

        return [
            'type'         => 'detail',
            'period'       => $range['period'],
            'period_label' => $this->periodLabel($range['period']),
            'range'        => $this->rangeMeta($range),
            'filters'      => $this->filterMeta($filters),
            'summary'      => $this->summarize($rows),
            'rows'         => $rows->values()->map(function ($row, $index) {
                return $this->mapDetailRow($row, $index + 1);
            })->all(),
            'generated_at' => $this->humanNow(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Range resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the [from, to] window plus bucketing metadata for a period.
     *
     * @return array{period:string, from:Carbon, to:Carbon, label:string, bucket:string}
     */
    public function resolveRange(string $period, ?string $date): array
    {
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
                return range(0, 23);
            case 'month':
                return range(1, 12);
            case 'day':
            default:
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
    protected function bucketLabel(int $key, string $bucket): string
    {
        switch ($bucket) {
            case 'hour':
                return sprintf('%02d:00', $key);
            case 'month':
                return self::SHORT_MONTHS[$key] ?? (string) $key;
            case 'day':
            default:
                return sprintf('%02d', $key);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Mapping / meta
    |--------------------------------------------------------------------------
    */

    protected function mapDetailRow(KartuAccessLog $row, int $no): array
    {
        $owner = optional(optional($row->kartu)->user)->name
            ?: optional($row->user)->name
            ?: 'Tidak dikenal';

        return [
            'no'              => $no,
            'tapped_at'       => optional($row->tapped_at)->toIso8601String(),
            'tapped_at_label' => $row->tapped_at
                ? $row->tapped_at->locale('id')->isoFormat('DD MMM Y, HH:mm:ss')
                : '-',
            'card_number'     => $row->card_number,
            'no_plat'         => $row->no_plat ?: '-',
            'owner'           => $owner,
            'direction'       => (int) $row->direction,
            'direction_label' => $row->direction_label,
            'access_granted'  => (bool) $row->access_granted,
            'result_label'    => $row->access_granted ? 'Diterima' : 'Ditolak',
            'reason'          => $row->reason,
            'reason_label'    => Kartu::REASON_MESSAGES[$row->reason] ?? ucfirst(str_replace('_', ' ', (string) $row->reason)),
            'gate'            => $row->gate ?: '-',
        ];
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

        return $active;
    }

    protected function periodLabel(string $period): string
    {
        $labels = [
            'harian'   => 'Harian',
            'bulanan'  => 'Bulanan',
            'tahunan'  => 'Tahunan',
        ];

        return $labels[$period] ?? ucfirst($period);
    }

    protected function humanNow(): string
    {
        return Carbon::now()->locale('id')->isoFormat('DD MMMM Y, HH:mm');
    }
}
