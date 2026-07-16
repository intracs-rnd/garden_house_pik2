<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    protected UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * Register a new user and issue an access token.
     *
     * @return array{user: \App\Models\User, token: string}
     */
    public function register(array $data): array
    {
        $data['password'] = Hash::make($data['password']);

        $user = $this->userRepository->create($data);

        return [
            'user'  => $user,
            'token' => $user->createToken('auth_token')->plainTextToken,
        ];
    }

    /**
     * Attempt to authenticate a user and issue an access token.
     *
     * @return array{user: \App\Models\User, token: string}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function login(array $credentials): array
    {
        /** @var User|null $user */
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (property_exists($user, 'is_active') && $user->is_active === false) {
            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        return [
            'user'  => $user,
            'token' => $user->createToken('auth_token')->plainTextToken,
        ];
    }

    /**
     * Revoke the current access token (logout).
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token) {
            $token->delete();
        }
    }

    /**
     * Revoke every token that belongs to the user.
     */
    public function logoutFromAllDevices(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Verify that a user with the given email exists in the system.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function verifyEmailExists(string $email): User
    {
        /** @var User|null $user */
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Email anda tidak ada di sistem.'],
            ]);
        }

        return $user;
    }

    /**
     * Reset a user's password directly by their email address (no token).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function resetPasswordByEmail(string $email, string $newPassword): User
    {
        $user = $this->verifyEmailExists($email);

        $user->password = Hash::make($newPassword);
        $user->save();

        // Invalidate any existing API tokens so old sessions can't linger.
        $user->tokens()->delete();

        return $user;
    }

    /**
     * Reset the authenticated user's password to a new one.
     */
    public function changePassword(User $user, string $newPassword): User
    {

        $user->password = Hash::make($newPassword);
        $user->save();

        return $user;
    }
}
