<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KartuAccessLog;
use App\Repositories\CategoryRepository;
use App\Repositories\KartuRepository;
use App\Repositories\KendaraanRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    protected UserRepository $userRepository;
    protected KendaraanRepository $kendaraanRepository;
    protected CategoryRepository $categoryRepository;
    protected KartuRepository $kartuRepository;

    public function __construct(
        UserRepository $userRepository,
        KendaraanRepository $kendaraanRepository,
        CategoryRepository $categoryRepository,
        KartuRepository $kartuRepository
    ) {
        $this->userRepository      = $userRepository;
        $this->kendaraanRepository = $kendaraanRepository;
        $this->categoryRepository  = $categoryRepository;
        $this->kartuRepository     = $kartuRepository;
    }

    /**
     * Return aggregated statistics for the dashboard.
     */
    public function index(): JsonResponse
    {
        $today = now()->startOfDay();

        $vehiclesIn = \App\Models\KartuAccessLog::where('tapped_at', '>=', $today)
            ->where('direction', \App\Models\KartuAccessLog::DIRECTION_IN)
            ->where('access_granted', true)
            ->count();

        $vehiclesOut = \App\Models\KartuAccessLog::where('tapped_at', '>=', $today)
            ->where('direction', \App\Models\KartuAccessLog::DIRECTION_OUT)
            ->where('access_granted', true)
            ->count();

        $stats = [
            'total_users'        => $this->userRepository->query()->count(),
            'total_kendaraan'    => $this->kendaraanRepository->query()->count(),
            'total_category'     => $this->categoryRepository->query()->count(),
            'total_kartu'        => $this->kartuRepository->query()->count(),
            'kartu_by_status'    => $this->kartuRepository->countByStatus(),
            'kendaraan_di_dalam' => max($vehiclesIn - $vehiclesOut, 0),
        ];

        return $this->successResponse($stats, 'Dashboard statistics retrieved successfully.');
    }

    /**
     * Return activity trends for the last 7 days.
     * Returns daily aggregated data: vehicles in, vehicles out, and total activities.
     */
    public function activityTrends(\Illuminate\Http\Request $request): JsonResponse
    {
        $endDateParam = $request->input('end_date');
        $endDate = $endDateParam ? \Carbon\Carbon::parse($endDateParam)->endOfDay() : now()->endOfDay();
        $startDate = $endDate->copy()->subDays(6)->startOfDay();

        // Get data for 7 days ending at endDate
        $trends = KartuAccessLog::select(
            DB::raw('DATE(tapped_at) as date'),
            DB::raw('SUM(CASE WHEN direction = ' . KartuAccessLog::DIRECTION_IN . ' AND access_granted = true THEN 1 ELSE 0 END) as vehicles_in'),
            DB::raw('SUM(CASE WHEN direction = ' . KartuAccessLog::DIRECTION_OUT . ' AND access_granted = true THEN 1 ELSE 0 END) as vehicles_out'),
            DB::raw('COUNT(*) as total_activities')
        )
            ->whereBetween('tapped_at', [$startDate, $endDate])
            ->groupBy(DB::raw('DATE(tapped_at)'))
            ->orderBy('date', 'asc')
            ->get();

        // Fill in missing dates with zero values
        $result = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $endDate->copy()->subDays($i)->format('Y-m-d');
            $dayData = $trends->firstWhere('date', $date);

            $result[] = [
                'date' => $date,
                'vehicles_in' => $dayData ? (int) $dayData->vehicles_in : 0,
                'vehicles_out' => $dayData ? (int) $dayData->vehicles_out : 0,
                'vehicles_inside' => $dayData 
                    ? max((int) $dayData->vehicles_in - (int) $dayData->vehicles_out, 0) 
                    : 0,
                'total_activities' => $dayData ? (int) $dayData->total_activities : 0,
            ];
        }

        return $this->successResponse($result, 'Activity trends retrieved successfully.');
    }
}
