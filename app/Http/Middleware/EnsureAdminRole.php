<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $adminUser = $this->resolveAdminUser($request);

        if (!$adminUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'Admin authentication context is required.',
            ], 401);
        }

        if (!$adminUser->isAdmin()) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not authorized to access this admin endpoint.',
            ], 403);
        }

        $request->attributes->set('admin_user', $adminUser);

        return $next($request);
    }

    private function resolveAdminUser(Request $request): ?User
    {
        $adminUserId = $this->inputValue($request, 'admin_user_id');
        $adminLookupEmail = $this->inputValue($request, 'admin_lookup_email');

        if ($adminUserId === null && $adminLookupEmail === null) {
            $adminUserId = $this->inputValue($request, 'user_id');
            $adminLookupEmail = $this->inputValue($request, 'lookup_email');
        }

        if ($adminUserId !== null && $adminUserId !== '') {
            return User::query()->find((int) $adminUserId);
        }

        if (is_string($adminLookupEmail) && trim($adminLookupEmail) !== '') {
            return User::query()->where('email', trim($adminLookupEmail))->first();
        }

        return null;
    }

    private function inputValue(Request $request, string $key): mixed
    {
        return $request->input($key, $request->query($key));
    }
}
