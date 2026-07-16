<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository extends BaseRepository
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    /**
     * Find a user by email address.
     *
     * @return \App\Models\User|null
     */
    public function findByEmail(string $email)
    {
        return $this->findBy('email', $email);
    }

    /**
     * Search users by name or email with pagination.
     */
    public function search(?string $term, int $perPage = 15)
    {
        return $this->model->newQuery()
            ->when($term, function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('no_kk', 'like', "%{$term}%");
            })
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Soft delete a user: flag it as deleted (is_deleted = true)
     * instead of permanently removing the record.
     */
    public function delete($id): bool
    {
        return $this->findOrFail($id)->softDelete();
    }

    /**
     * Restore a previously soft-deleted user.
     */
    public function restore($id): bool
    {
        return $this->model->newQuery()
            ->withDeleted()
            ->findOrFail($id)
            ->restore();
    }
}
