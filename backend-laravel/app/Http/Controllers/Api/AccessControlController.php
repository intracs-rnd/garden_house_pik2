<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoleFeaturePermission;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessControlController extends Controller
{
    protected PermissionService $permissions;

    public function __construct(PermissionService $permissions)
    {
        $this->permissions = $permissions;
    }

    /**
     * Return the feature list, manageable roles, and the current permission matrix.
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            $this->permissions->matrix(),
            'Access control matrix retrieved successfully.'
        );
    }

    /**
     * Replace the permissions of a single role.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role'                => ['required', 'string', Rule::in(PermissionService::MANAGEABLE_ROLES)],
            'permissions'         => ['present', 'array'],
            'permissions.*.feature_key' => ['required', 'string'],
            'permissions.*.access'      => ['nullable', 'string', Rule::in([
                RoleFeaturePermission::ACCESS_VIEW,
                RoleFeaturePermission::ACCESS_MANAGE,
            ])],
        ]);

        $this->permissions->syncRole($data['role'], $data['permissions']);

        return $this->successResponse(
            $this->permissions->matrix(),
            'Hak akses berhasil diperbarui.'
        );
    }
}
