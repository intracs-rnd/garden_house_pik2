<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\CategoryRepository;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    protected CategoryRepository $categoryRepository;

    public function __construct(CategoryRepository $categoryRepository)
    {
        $this->categoryRepository = $categoryRepository;
    }

    /**
     * Display a listing of categories.
     */
    public function index(): JsonResponse
    {
        $categories = $this->categoryRepository->query()->orderBy('name')->get();

        return $this->successResponse($categories, 'Categories retrieved successfully.');
    }

    /**
     * Display the specified category.
     */
    public function show($id): JsonResponse
    {
        $category = $this->categoryRepository->findOrFail($id);

        return $this->successResponse($category, 'Category retrieved successfully.');
    }
}
