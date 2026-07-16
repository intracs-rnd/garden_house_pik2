<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Allow the request only when the authenticated user is a super admin.
     *
     * Admin (and every other role) is limited to read-only access and file
     * downloads; any write/action endpoint must be guarded by this middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki izin untuk melakukan tindakan ini.',
            ], 403);
        }

        return $next($request);
    }
}
