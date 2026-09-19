<?php

// Test logging route - will be removed after testing
Route::get('/test-logging', function() {
    \Log::emergency('TEST LOG - EMERGENCY LEVEL');
    \Log::error('TEST LOG - ERROR LEVEL');  
    \Log::warning('TEST LOG - WARNING LEVEL');
    \Log::info('TEST LOG - INFO LEVEL');
    
    return response()->json([
        'message' => 'Test logs written',
        'log_channel' => config('logging.default'),
        'log_file' => storage_path('logs/laravel.log')
    ]);
})->name('test.logging');