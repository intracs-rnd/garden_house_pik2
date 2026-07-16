<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\RoleFeaturePermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    /** Role that always has full access to every feature. */
    public const SUPER_ROLE = 'superadmin';

    /** Roles that can be managed through the access-control UI. */
    public const MANAGEABLE_ROLES = ['admin', 'staff', 'user'];

    /**
     * Build the { feature_key => access } map for a given role.
     * Superadmin implicitly gets 'manage' on every feature.
     */
    public function mapForRole(?string $role): array
    {
        if ($role === self::SUPER_ROLE) {
            return Feature::query()
                ->pluck('key')
                ->mapWithKeys(fn ($key) => [$key => RoleFeaturePermission::ACCESS_MANAGE])
                ->all();
        }

        if (! $role) {
            return [];
        }

        return RoleFeaturePermission::query()
            ->join('features', 'features.id', '=', 'role_feature_permissions.feature_id')
            ->where('role_feature_permissions.role', $role)
            ->pluck('role_feature_permissions.access', 'features.key')
            ->all();
    }

    /**
     * Permission map for a specific user (based on their role).
     */
    public function userPermissions(User $user): array
    {
        return $this->mapForRole($user->role);
    }

    /**
     * Determine whether a role satisfies the required access level for a feature.
     */
    public function roleCan(?string $role, string $featureKey, string $level = RoleFeaturePermission::ACCESS_VIEW): bool
    {
        if ($role === self::SUPER_ROLE) {
            return true;
        }

        $map = $this->mapForRole($role);
        $granted = $map[$featureKey] ?? null;

        if ($granted === null) {
            return false;
        }

        if ($level === RoleFeaturePermission::ACCESS_MANAGE) {
            return $granted === RoleFeaturePermission::ACCESS_MANAGE;
        }

        // 'view' is satisfied by both 'view' and 'manage'.
        return true;
    }

    /**
     * Data needed by the access-control settings screen:
     * the list of features, manageable roles, and the current matrix.
     */
    public function matrix(): array
    {
        $features = Feature::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'key', 'label']);

        $permissions = RoleFeaturePermission::query()
            ->join('features', 'features.id', '=', 'role_feature_permissions.feature_id')
            ->get(['role_feature_permissions.role', 'features.key as feature_key', 'role_feature_permissions.access']);

        $matrix = [];
        foreach ($permissions as $p) {
            $matrix[$p->role][$p->feature_key] = $p->access;
        }

        return [
            'features'    => $features,
            'roles'       => self::MANAGEABLE_ROLES,
            'permissions' => $matrix,
        ];
    }

    /**
     * Bulk replace the permissions for a single role.
     *
     * @param  array<int, array{feature_key: string, access: ?string}>  $items
     */
    public function syncRole(string $role, array $items): void
    {
        if ($role === self::SUPER_ROLE) {
            // Superadmin is locked to full access and cannot be edited.
            return;
        }

        $featureIds = Feature::query()->pluck('id', 'key');

        DB::transaction(function () use ($role, $items, $featureIds) {
            // Clear existing rows for this role, then re-insert the granted ones.
            RoleFeaturePermission::query()->where('role', $role)->delete();

            $now = now();
            $rows = [];

            foreach ($items as $item) {
                $key = $item['feature_key'] ?? null;
                $access = $item['access'] ?? null;

                if (! $key || ! isset($featureIds[$key])) {
                    continue;
                }

                // A null/none access means "no access": simply skip the row.
                if (! in_array($access, [RoleFeaturePermission::ACCESS_VIEW, RoleFeaturePermission::ACCESS_MANAGE], true)) {
                    continue;
                }

                $rows[] = [
                    'role'       => $role,
                    'feature_id' => $featureIds[$key],
                    'access'     => $access,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! empty($rows)) {
                RoleFeaturePermission::query()->insert($rows);
            }
        });
    }
}
