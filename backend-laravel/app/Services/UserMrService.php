<?php

namespace App\Services;

use App\Repositories\UserMrRepository;
use Illuminate\Support\Str;

class UserMrService
{
    protected UserMrRepository $userMrRepository;

    public function __construct(UserMrRepository $userMrRepository)
    {
        $this->userMrRepository = $userMrRepository;
    }

    /**
     * Paginated, searchable list of users.
     */
    public function list(?string $search = null, int $perPage = 15)
    {
        return $this->userMrRepository->search($search, $perPage);
    }

    /**
     * Get a single user by uuid.
     *
     * @return \App\Models\UserMr
     */
    public function find($uuid)
    {
        return $this->userMrRepository->findOrFail($uuid);
    }

    /**
     * Create a new user with bcrypt hashing (rounds 12).
     *
     * @return \App\Models\UserMr
     */
    public function create(array $data)
    {
        $data['id'] = Str::uuid();
        $data['password'] = bcrypt($data['password']);
        $data['created_at'] = now();

        return $this->userMrRepository->create($data);
    }

    /**
     * Update an existing user.
     *
     * @return \App\Models\UserMr
     */
    public function update($uuid, array $data)
    {
        if (! empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        return $this->userMrRepository->update($uuid, $data);
    }

    /**
     * Delete a user.
     */
    public function delete($uuid): bool
    {
        return $this->userMrRepository->delete($uuid);
    }
}
