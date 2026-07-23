<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserMrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserMrController extends Controller
{
    protected UserMrService $userMrService;

    public function __construct(UserMrService $userMrService)
    {
        $this->userMrService = $userMrService;
    }

    /**
     * Display a paginated listing of users.
     */
    public function index(Request $request): JsonResponse
    {
        $users = $this->userMrService->list(
            $request->query('search'),
            (int) $request->query('per_page', 15)
        );

        return $this->paginatedResponse($users, 'User MR retrieved successfully.');
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users_mr,username'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->userMrService->create($data);

        return $this->successResponse($user, 'User MR created successfully.', 201);
    }

    /**
     * Display the specified user.
     */
    public function show($uuid): JsonResponse
    {
        $user = $this->userMrService->find($uuid);

        return $this->successResponse($user, 'User MR retrieved successfully.');
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, $uuid): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['sometimes', 'required', 'string', 'max:255'],
            'username' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('users_mr', 'username')->ignore($uuid, 'id'),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->userMrService->update($uuid, $data);

        return $this->successResponse($user, 'User MR updated successfully.');
    }

    /**
     * Delete the specified user.
     */
    public function destroy($uuid): JsonResponse
    {
        $this->userMrService->delete($uuid);

        return $this->successResponse(null, 'User MR deleted successfully.');
    }
}
