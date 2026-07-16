<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppErrorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super-admin only: browse & download the application error log.
 *
 * Errors are captured automatically by App\Exceptions\Handler; this controller
 * only exposes them for review and export so the operator can quickly see
 * "what broke and where".
 */
class ErrorLogController extends Controller
{
    /**
     * GET /api/error-logs — paginated list with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $query = AppErrorLog::query()->latest('created_at')->latest('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('message', 'ilike', "%{$search}%")
                    ->orWhere('type', 'ilike', "%{$search}%")
                    ->orWhere('url', 'ilike', "%{$search}%")
                    ->orWhere('user_name', 'ilike', "%{$search}%");
            });
        }

        if ($level = trim((string) $request->input('level'))) {
            $query->where('level', $level);
        }

        if ($status = $request->input('status_code')) {
            $query->where('status_code', (int) $status);
        }

        return $this->paginatedResponse($query->paginate($perPage), 'Log error berhasil dimuat.');
    }

    /**
     * GET /api/error-logs/{errorLog} — full detail (incl. stack trace).
     */
    public function show(AppErrorLog $errorLog): JsonResponse
    {
        return $this->successResponse($errorLog, 'Detail log error berhasil dimuat.');
    }

    /**
     * GET /api/error-logs/download — export all logs as CSV (default) or JSON.
     */
    public function download(Request $request): StreamedResponse
    {
        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp  = now()->format('Ymd_His');

        $query = AppErrorLog::query()->latest('created_at')->latest('id');

        if ($format === 'json') {
            $filename = "error-logs-{$stamp}.json";

            return response()->streamDownload(function () use ($query) {
                echo $query->get()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }, $filename, ['Content-Type' => 'application/json']);
        }

        $filename = "error-logs-{$stamp}.csv";

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel opens the file with correct encoding.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'ID', 'Waktu', 'Level', 'Status', 'Tipe', 'Pesan',
                'File', 'Baris', 'Method', 'URL', 'IP', 'User',
            ]);

            $query->chunk(500, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->id,
                        optional($log->created_at)->format('Y-m-d H:i:s'),
                        $log->level,
                        $log->status_code,
                        $log->type,
                        $log->message,
                        $log->file,
                        $log->line,
                        $log->method,
                        $log->url,
                        $log->ip,
                        $log->user_name,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * DELETE /api/error-logs — clear all logs (housekeeping).
     */
    public function clear(): JsonResponse
    {
        $deleted = AppErrorLog::query()->delete();

        return $this->successResponse(['deleted' => $deleted], 'Semua log error berhasil dihapus.');
    }
}
