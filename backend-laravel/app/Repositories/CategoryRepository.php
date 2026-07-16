<?php

namespace App\Repositories;

use App\Models\Category;

class CategoryRepository extends BaseRepository
{
    public function __construct(Category $model)
    {
        parent::__construct($model);
    }

    /**
     * Get only active categories.
     */
    public function active()
    {
        return $this->model->newQuery()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a category by its slug.
     *
     * @return \App\Models\Category|null
     */
    public function findBySlug(string $slug)
    {
        return $this->findBy('slug', $slug);
    }
}
