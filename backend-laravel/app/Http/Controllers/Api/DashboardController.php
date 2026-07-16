<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CategoryRepository;
use App\Repositories\KartuRepository;
use App\Repositories\KendaraanRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;

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
        $stats = [
            'total_users'        => $this->userRepository->query()->count(),
            'total_kendaraan'    => $this->kendaraanRepository->query()->count(),
            'total_category'     => $this->categoryRepository->query()->count(),
            'total_kartu'        => $this->kartuRepository->query()->count(),
            'kartu_by_status'    => $this->kartuRepository->countByStatus(),
        ];

        return $this->successResponse($stats, 'Dashboard statistics retrieved successfully.');
    }
}
