<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Display a paginated listing of users.
     */
    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->list(
            $request->query('search'),
            (int) $request->query('per_page', 15)
        );

        return $this->paginatedResponse($users, 'Users retrieved successfully.');
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role'     => ['nullable', 'string', 'in:superadmin,admin,staff,user'],
            'type'     => ['nullable', 'string', 'in:warga,tamu'],
            'phone'    => ['nullable', 'string', 'max:20'],
            'no_kk'    => [
                'required', 'string', 'digits:16',
                Rule::unique('users', 'no_kk')
                    ->where(fn ($query) => $query->where('is_deleted', false)),
            ],
        ], [
            'no_kk.unique' => 'Maaf akun dengan KK tersebut sudah terdaftar',
        ]);

        $user = $this->userService->create($data);

        return $this->successResponse($user, 'User created successfully.', 201);
    }

    /**
     * Display the specified user.
     */
    public function show($id): JsonResponse
    {
        $user = $this->userService->find($id);

        return $this->successResponse($user, 'User retrieved successfully.');
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['sometimes', 'required', 'string', 'max:255'],
            'email'    => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')
                    ->ignore($id)
                    ->where(fn ($query) => $query->where('is_deleted', false)),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role'     => ['nullable', 'string', 'in:superadmin,admin,staff,user'],
            'type'     => ['nullable', 'string', 'in:warga,tamu'],
            'phone'    => ['nullable', 'string', 'max:20'],
            'no_kk'    => [
                'sometimes', 'required', 'string', 'digits:16',
                Rule::unique('users', 'no_kk')
                    ->ignore($id)
                    ->where(fn ($query) => $query->where('is_deleted', false)),
            ],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'no_kk.unique' => 'Maaf akun dengan KK tersebut sudah terdaftar',
        ]);

        $user = $this->userService->update($id, $data);

        return $this->successResponse($user, 'User updated successfully.');
    }

    /**
     * Remove the specified user.
     */
    public function destroy($id): JsonResponse
    {
        $this->userService->delete($id);

        return $this->successResponse(null, 'User deleted successfully.');
    }
}
