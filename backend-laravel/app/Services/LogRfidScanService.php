<?php

namespace App\Services;

use App\Models\Card;
use App\Models\LogCctv;
use App\Models\LogRfidScan;
use App\Repositories\LogRfidScanRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Business logic around the log_rfid_scan table.
 *
 * Handles listing, filtering, recording new scans and resolving related
 * card / CCTV snapshot data.
 */
class LogRfidScanService
{
    protected LogRfidScanRepository $repository;

    public function __construct(LogRfidScanRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Paginated list of scans with optional filters.
     *
     * @param array<string, mixed> $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->filter($filters, $perPage);
    }

    /**
     * Fetch a single scan (with card + cctv relations) or throw 404.
     */
    public function find($id): LogRfidScan
    {
        /** @var LogRfidScan $scan */
        $scan = $this->repository->findOrFail($id);

        return $this->attachRelations($scan);
    }

    /**
     * Scan history for a specific gate.
     */
    public function historyByGate(string $gateId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->historyByGate($gateId, $perPage);
    }

    /**
     * Scan history for a specific card UID.
     */
    public function historyByUid(string $uid, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->historyByUid($uid, $perPage);
    }

    /**
     * Latest N scans across all gates, hydrated with card + cctv.
     *
     * @return Collection<int, LogRfidScan>
     */
    public function latest(int $limit = 20): Collection
    {
        return $this->repository->latest($limit)
            ->map(fn (LogRfidScan $scan) => $this->attachRelations($scan));
    }

    /**
     * Latest scan per gate — one row per unique gate_id.
     *
     * @return Collection<int, LogRfidScan>
     */
    public function latestPerGate(): Collection
    {
        return $this->repository->latestPerGate()
            ->map(fn (LogRfidScan $scan) => $this->attachRelations($scan));
    }

    /**
     * Record a new RFID scan.
     *
     * Accepts an associative array with at least gate_id + uid; event_ts and
     * created_at default to "now" (Asia/Jakarta) when not provided. The card
     * and cctv relations are attached to the returned model.
     *
     * @param array<string, mixed> $data
     */
    public function record(array $data): LogRfidScan
    {
        $now = Carbon::now('Asia/Jakarta');

        $payload = [
            'gate_id'    => $data['gate_id'] ?? null,
            'event_ts'   => $data['event_ts']   ?? $now,
            'created_at' => $data['created_at'] ?? $now,
            'uid'        => $data['uid'] ?? null,
            'result'     => $data['result'] ?? LogRfidScan::RESULT_ALLOW,
            'cctv_id'    => $data['cctv_id'] ?? null,
        ];

        /** @var LogRfidScan $scan */
        $scan = $this->repository->create($payload);

        return $this->attachRelations($scan);
    }

    /**
     * Aggregate counters for a date range, grouped by result.
     *
     * @return array<string, int>
     */
    public function countsByResult(?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->repository->countsByResult($dateFrom, $dateTo);
    }

    /**
     * Hydrate card + cctv relations manually.
     *
     * Card lives on the pgsql_cards connection while log_rfid_scan lives on
     * pgsql_replica, so we can't use eager loading via a plain ->with() —
     * we resolve each relation on its own connection and attach the result.
     */
    protected function attachRelations(LogRfidScan $scan): LogRfidScan
    {
        if ($scan->uid) {
            $scan->setRelation('card', Card::query()->where('uid', $scan->uid)->first());
        }

        if ($scan->cctv_id) {
            $scan->setRelation('cctv', LogCctv::query()->find($scan->cctv_id));
        }

        return $scan;
    }
}
