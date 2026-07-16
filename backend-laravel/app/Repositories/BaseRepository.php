<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Base repository providing common Eloquent data-access operations.
 */
abstract class BaseRepository
{
    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Get all records.
     */
    public function all(array $columns = ['*']): Collection
    {
        return $this->model->newQuery()->get($columns);
    }

    /**
     * Paginate records.
     */
    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator
    {
        return $this->model->newQuery()->latest()->paginate($perPage, $columns);
    }

    /**
     * Find a record by its primary key.
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function find($id, array $columns = ['*'])
    {
        return $this->model->newQuery()->find($id, $columns);
    }

    /**
     * Find a record or fail.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function findOrFail($id, array $columns = ['*'])
    {
        return $this->model->newQuery()->findOrFail($id, $columns);
    }

    /**
     * Find a record by an attribute.
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function findBy(string $attribute, $value)
    {
        return $this->model->newQuery()->where($attribute, $value)->first();
    }

    /**
     * Create a new record.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function create(array $data)
    {
        return $this->model->newQuery()->create($data);
    }

    /**
     * Update an existing record.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function update($id, array $data)
    {
        $record = $this->findOrFail($id);
        $record->update($data);

        return $record->fresh();
    }

    /**
     * Delete a record.
     */
    public function delete($id): bool
    {
        return (bool) $this->findOrFail($id)->delete();
    }

    /**
     * Access the underlying query builder.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        return $this->model->newQuery();
    }
}
