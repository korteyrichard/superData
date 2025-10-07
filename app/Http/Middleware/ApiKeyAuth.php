<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->bearerToken();
        
        if (!$apiKey) {
            return response()->json(['message' => 'API key required'], 401);
        }

        $user = User::where('api_key', $apiKey)->first();
        
        if (!$user) {
            return response()->json(['message' => 'Invalid API key'], 401);
        }

        auth()->login($user);
        
        return $next($request);
    }
}