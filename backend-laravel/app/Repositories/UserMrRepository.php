<?php

namespace App\Repositories;

use App\Models\UserMr;

class UserMrRepository extends BaseRepository
{
    public function __construct(UserMr $model)
    {
        parent::__construct($model);
    }

    /**
     * Find a user by username.
     *
     * @return \App\Models\UserMr|null
     */
    public function findByUsername(string $username)
    {
        return $this->findBy('username', $username);
    }

    /**
     * Search users by name or username with pagination.
     */
    public function search(?string $term, int $perPage = 15)
    {
        return $this->model->newQuery()
            ->when($term, function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%");
            })
            ->latest('created_at')
            ->paginate($perPage);
    }
}
