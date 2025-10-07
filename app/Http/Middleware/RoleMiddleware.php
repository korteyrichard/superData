<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        // Check if user is authenticated
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();
        $allowedRoles = explode(',', $role);

        // Check if user has any of the required roles
        if (empty($user->role) || !in_array($user->role, $allowedRoles)) {
            abort(403, 'Access denied. You need one of these roles: ' . implode(', ', $allowedRoles));
        }

        return $next($request);
    }
}