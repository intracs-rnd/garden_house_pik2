<?php

use App\Http\Controllers\Api\AccessControlController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CameraController;
use App\Http\Controllers\Api\CardReplicationController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ErrorLogController;
use App\Http\Controllers\Api\GateController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\IuranController;
use App\Http\Controllers\Api\KartuController;
use App\Http\Controllers\Api\KendaraanController;
use App\Http\Controllers\Api\LogRfidConnController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserMrController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes here are prefixed with "/api" and use the "api" middleware
| group (see App\Providers\RouteServiceProvider).
|
*/

// ---- Public routes ----
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// ---- Protected routes (Sanctum) ----
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/activity-trends', [DashboardController::class, 'activityTrends']);

    // ---- Application error / bug log (SUPER ADMIN ONLY) ----
    // Auto-captured server errors, reviewable and downloadable (CSV / JSON).
    Route::middleware('superadmin')->group(function () {
        Route::get('/error-logs/download', [ErrorLogController::class, 'download']);
        Route::get('/error-logs', [ErrorLogController::class, 'index']);
        Route::get('/error-logs/{errorLog}', [ErrorLogController::class, 'show']);
        Route::delete('/error-logs', [ErrorLogController::class, 'clear']);
    });

    // ---- User MR management (SUPER ADMIN ONLY) ----
    // CRUD operations for managing MR users with bcrypt password hashing.
    Route::middleware('superadmin')->group(function () {
        Route::get('/user-mr', [UserMrController::class, 'index']);
        Route::post('/user-mr', [UserMrController::class, 'store']);
        Route::get('/user-mr/{uuid}', [UserMrController::class, 'show']);
        Route::match(['put', 'patch'], '/user-mr/{uuid}', [UserMrController::class, 'update']);
        Route::delete('/user-mr/{uuid}', [UserMrController::class, 'destroy']);
    });

    // ---- Gate logs (MQTT integration) ----
    Route::post('/gate/log', [GateController::class, 'logGateAction']);
    Route::get('/gate/logs', [GateController::class, 'getAllLogs']);
    Route::get('/gate/logs/{gateId}', [GateController::class, 'getLogsByGateId']);

    // RFID gate reader connection status (live heartbeat per gate)
    Route::get('/rfid-conn/status', [LogRfidConnController::class, 'index']);
    Route::get('/rfid-conn/history/{gateId}', [LogRfidConnController::class, 'history']);

    // Live CCTV feeds for the dashboard grid (name + HLS only, no credentials).
    Route::get('/cameras/feeds', [CameraController::class, 'feeds']);

    // ---- Image Upload Service ----
    // Endpoint untuk fetch gambar dari image upload API (192.168.214.7:4000)
    Route::post('/images/fetch', [ImageController::class, 'fetchImage']);
    Route::post('/images/fetch-multiple', [ImageController::class, 'fetchMultipleImages']);

    // ---- Live CCTV camera configuration ----
    // Reading the full config (incl. RTSP URLs) needs "view"; saving/applying
    // (which touches MediaMTX) needs "manage".
    Route::middleware('feature:cameras,view')->group(function () {
        Route::get('/cameras', [CameraController::class, 'index']);
    });
    Route::middleware('feature:cameras,manage')->group(function () {
        Route::put('/cameras', [CameraController::class, 'update']);
        Route::post('/cameras/apply', [CameraController::class, 'apply']);
    });

    // Laporan transaksi (rekap & detail: harian / bulanan / tahunan, PDF & Excel)
    Route::middleware('feature:reports,view')->group(function () {
        Route::get('/reports/recap', [ReportController::class, 'recap']);
        Route::get('/reports/detail', [ReportController::class, 'detail']);
        Route::get('/reports/recap/pdf', [ReportController::class, 'recapPdf']);
        Route::get('/reports/detail/pdf', [ReportController::class, 'detailPdf']);
        Route::get('/reports/recap/excel', [ReportController::class, 'recapExcel']);
        Route::get('/reports/detail/excel', [ReportController::class, 'detailExcel']);
        // Laporan kontrol gate (log_gate + gate_manual_control per periode)
        Route::get('/reports/gate-control', [GateController::class, 'getLogsReport']);
        Route::get('/reports/gate-control/pdf', [GateController::class, 'gateControlPdf']);
        Route::get('/reports/gate-control/excel', [GateController::class, 'gateControlExcel']);
    });

    // Categories (read-only)
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);

    // ---- Access control (RBAC) management: super admin / access_control feature ----
    Route::get('/access-control', [AccessControlController::class, 'index'])
        ->middleware('feature:access_control,view');
    Route::put('/access-control', [AccessControlController::class, 'update'])
        ->middleware('feature:access_control,manage');

    // ---- Users ----
    Route::middleware('feature:users,view')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
    });
    Route::middleware('feature:users,manage')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });

    // ---- Kendaraan ----
    Route::middleware('feature:kendaraan,view')->group(function () {
        Route::get('/kendaraan', [KendaraanController::class, 'index']);
        Route::get('/kendaraan/{kendaraan}', [KendaraanController::class, 'show']);
    });
    Route::middleware('feature:kendaraan,manage')->group(function () {
        Route::post('/kendaraan', [KendaraanController::class, 'store']);
        Route::match(['put', 'patch'], '/kendaraan/{kendaraan}', [KendaraanController::class, 'update']);
        Route::delete('/kendaraan/{kendaraan}', [KendaraanController::class, 'destroy']);
    });

    // ---- Kartu akses ----
    // Custom routes are declared before the resource show route to avoid the
    // {kartu} wildcard capturing these paths.
    Route::middleware('feature:kartu,view')->group(function () {
        Route::post('/kartu/status-check', [KartuController::class, 'statusByNumber']);
        Route::get('/kartu-logs', [KartuController::class, 'accessLogs']);
        Route::get('/kartu/{id}/status', [KartuController::class, 'status']);
        Route::get('/kartu/{id}/logs', [KartuController::class, 'logs']);
        Route::get('/kartu', [KartuController::class, 'index']);
        Route::get('/kartu/{kartu}', [KartuController::class, 'show']);
    });

    // Gate simulation (tab-in / tab-out) is gated by the "kartu_gate" feature.
    Route::middleware('feature:kartu_gate,manage')->group(function () {
        Route::post('/kartu/tab-in', [KartuController::class, 'tabIn']);
        Route::post('/kartu/tab-out', [KartuController::class, 'tabOut']);
        // Manual gate control (Buka/Tutup gate dari dashboard)
        Route::post('/gate/manual-control', [GateController::class, 'logManualControl']);
    });

    // Kartu mutations (create / update / delete / blacklist) require manage on "kartu".
    Route::middleware('feature:kartu,manage')->group(function () {
        Route::post('/kartu/{id}/blacklist', [KartuController::class, 'blacklist']);
        Route::post('/kartu/{id}/clear-blacklist', [KartuController::class, 'clearBlacklist']);
        Route::post('/kartu', [KartuController::class, 'store']);
        Route::match(['put', 'patch'], '/kartu/{kartu}', [KartuController::class, 'update']);
        Route::delete('/kartu/{kartu}', [KartuController::class, 'destroy']);
    });

    // ---- Transactions ----
    // Get active transaction by plate number for gate control validation
    Route::get('/transactions/active', [TransactionController::class, 'getActiveTransaction']);
    Route::get('/transactions/validate', [TransactionController::class, 'validatePlate']);
    
    // Complete a transaction (update status to COMPLETED)
    Route::patch('/transactions/{id}/complete', [TransactionController::class, 'completeTransaction']);
    
    // Full CRUD for transactions
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::match(['put', 'patch'], '/transactions/{id}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{id}', [TransactionController::class, 'destroy']);

    // ---- Iuran Perumahan ----
    // Semua user terautentikasi dapat melihat tagihan (warga: KK sendiri, admin: semua).
    Route::get('/iuran', [IuranController::class, 'index']);
    Route::get('/iuran/history', [IuranController::class, 'history']);
    Route::get('/iuran/{id}', [IuranController::class, 'show']);

    // Warga membayar tagihan iuran KK-nya sendiri.
    Route::post('/iuran/{id}/pay', [IuranController::class, 'pay']);

    // Admin / Super Admin: kelola tagihan dan generate batch per periode.
    Route::middleware('superadmin')->group(function () {
        Route::post('/iuran', [IuranController::class, 'store']);
        Route::put('/iuran/{id}', [IuranController::class, 'update']);
        Route::delete('/iuran/{id}', [IuranController::class, 'destroy']);
        Route::post('/iuran/generate', [IuranController::class, 'generate']);
        // Approve pembayaran (SUPERADMIN)
        Route::post('/iuran/pembayaran/{id}/approve', [IuranController::class, 'approvePayment']);
    });

    // ---- CARD REPLICATION (Cards sync antara Admin & Server) ----
    // Status replication (public untuk monitoring)
    Route::get('/cards/replication/status', [CardReplicationController::class, 'getReplicationStatus']);
    Route::get('/cards/replication/lag', [CardReplicationController::class, 'getReplicationLag']);
    Route::get('/cards/replication/changes', [CardReplicationController::class, 'getChangesSummary']);
    Route::get('/cards/replication/audit-logs', [CardReplicationController::class, 'getAuditLogs']);

    // Card CRUD dengan automatic audit logging
    Route::middleware('feature:cards,manage')->group(function () {
        Route::post('/cards', [CardReplicationController::class, 'createCard']);
        Route::put('/cards/{card}', [CardReplicationController::class, 'updateCard']);
        Route::delete('/cards/{card}', [CardReplicationController::class, 'deleteCard']);
    });

    // Admin: manage replication
    Route::middleware('superadmin')->group(function () {
        Route::post('/cards/replication/retry-failed', [CardReplicationController::class, 'retryFailedSyncs']);
        Route::post('/cards/replication/refresh-status', [CardReplicationController::class, 'refreshReplicationStatus']);
    });
});
