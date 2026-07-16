<?php

namespace App\Services;

use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;

class UserService
{
    protected UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * Paginated, searchable list of users.
     */
    public function list(?string $search = null, int $perPage = 15)
    {
        return $this->userRepository->search($search, $perPage);
    }

    /**
     * Get a single user by id.
     *
     * @return \App\Models\User
     */
    public function find($id)
    {
        return $this->userRepository->findOrFail($id);
    }

    /**
     * Create a new user.
     *
     * @return \App\Models\User
     */
    public function create(array $data)
    {
        $data['password'] = Hash::make($data['password']);

        return $this->userRepository->create($data);
    }

    /**
     * Update an existing user.
     *
     * @return \App\Models\User
     */
    public function update($id, array $data)
    {
        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        return $this->userRepository->update($id, $data);
    }

    /**
     * Delete a user.
     */
    public function delete($id): bool
    {
        return $this->userRepository->delete($id);
    }
}
