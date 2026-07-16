<?php

use App\Http\Controllers\Api\AccessControlController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CameraController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ErrorLogController;
use App\Http\Controllers\Api\GateController;
use App\Http\Controllers\Api\KartuController;
use App\Http\Controllers\Api\KendaraanController;
use App\Http\Controllers\Api\LogRfidConnController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;
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

    // ---- Application error / bug log (SUPER ADMIN ONLY) ----
    // Auto-captured server errors, reviewable and downloadable (CSV / JSON).
    Route::middleware('superadmin')->group(function () {
        Route::get('/error-logs/download', [ErrorLogController::class, 'download']);
        Route::get('/error-logs', [ErrorLogController::class, 'index']);
        Route::get('/error-logs/{errorLog}', [ErrorLogController::class, 'show']);
        Route::delete('/error-logs', [ErrorLogController::class, 'clear']);
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
    });

    // Kartu mutations (create / update / delete / blacklist) require manage on "kartu".
    Route::middleware('feature:kartu,manage')->group(function () {
        Route::post('/kartu/{id}/blacklist', [KartuController::class, 'blacklist']);
        Route::post('/kartu/{id}/clear-blacklist', [KartuController::class, 'clearBlacklist']);
        Route::post('/kartu', [KartuController::class, 'store']);
        Route::match(['put', 'patch'], '/kartu/{kartu}', [KartuController::class, 'update']);
        Route::delete('/kartu/{kartu}', [KartuController::class, 'destroy']);
    });
});
