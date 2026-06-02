<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class EnsureCodeCraftConfig
{
    public function handle(Request $request, Closure $next)
    {
        // Only check for order placement routes
        if ($request->is('place_order')) {
            $apiKey = env('CODECRAFT_API_KEY', '');
            
            // If API key is not loaded, clear config cache
            if (empty($apiKey)) {
                Log::warning('CodeCraft API key not loaded, clearing config cache');
                Artisan::call('config:clear');
                
                // Re-check after clearing cache
                $apiKey = env('CODECRAFT_API_KEY', '');
                if (!empty($apiKey)) {
                    Log::info('CodeCraft API key loaded after cache clear');
                }
            }
        }
        
        return $next($request);
    }
}