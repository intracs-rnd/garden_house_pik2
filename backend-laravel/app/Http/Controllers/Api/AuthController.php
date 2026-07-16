<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    protected AuthService $authService;

    protected PermissionService $permissionService;

    public function __construct(AuthService $authService, PermissionService $permissionService)
    {
        $this->authService = $authService;
        $this->permissionService = $permissionService;
    }

    /**
     * Serialize a user together with their effective feature permissions.
     */
    protected function userWithPermissions(User $user): array
    {
        $data = $user->toArray();
        $data['permissions'] = $this->permissionService->userPermissions($user);

        return $data;
    }

    /**
     * Register a new user.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')
                    ->where(fn ($query) => $query->where('is_deleted', false)),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'type'     => ['nullable', 'string', 'in:warga,tamu'],
            'phone'    => ['nullable', 'string', 'max:20'],
        ]);

        $result = $this->authService->register($data);

        return $this->successResponse([
            'user'       => $this->userWithPermissions($result['user']),
            'token'      => $result['token'],
            'token_type' => 'Bearer',
        ], 'Registration successful.', 201);
    }

    /**
     * Authenticate a user and return an access token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->authService->login($credentials);

        return $this->successResponse([
            'user'       => $this->userWithPermissions($result['user']),
            'token'      => $result['token'],
            'token_type' => 'Bearer',
        ], 'Login successful.');
    }

    /**
     * Verify that the given email exists in the system.
     * No email is sent; on success the SPA proceeds to the reset form.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $this->authService->verifyEmailExists($data['email']);

        return $this->successResponse(
            ['email' => $data['email']],
            'Email ditemukan. Silakan atur password baru Anda.'
        );
    }

    /**
     * Reset the password for the given (existing) email address.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->authService->resetPasswordByEmail($data['email'], $data['password']);

        return $this->successResponse(
            null,
            'Password berhasil direset. Silakan masuk dengan password baru Anda.'
        );
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->userWithPermissions($request->user()),
            'Authenticated user retrieved.'
        );
    }

    /**
     * Reset the authenticated user's password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->authService->changePassword(
            $request->user(),
            $data['password']
        );

        return $this->successResponse(null, 'Password berhasil diperbarui.');
    }

    /**
     * Log the user out (revoke the current token).
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->successResponse(null, 'Logged out successfully.');
    }
}
