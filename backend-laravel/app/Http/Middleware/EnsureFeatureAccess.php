<?php

namespace App\Http\Middleware;

use App\Models\RoleFeaturePermission;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureAccess
{
    protected PermissionService $permissions;

    public function __construct(PermissionService $permissions)
    {
        $this->permissions = $permissions;
    }

    /**
     * Allow the request only when the authenticated user's role has the
     * required access level for the given feature.
     *
     * Usage: ->middleware('feature:users,manage') or ->middleware('feature:reports')
     */
    public function handle(Request $request, Closure $next, string $feature, string $level = RoleFeaturePermission::ACCESS_VIEW): Response
    {
        $user = $request->user();

        if (! $user || ! $this->permissions->roleCan($user->role, $feature, $level)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki izin untuk mengakses fitur ini.',
            ], 403);
        }

        return $next($request);
    }
}
