<?php

/**
 * Quick Fix for Shop 404 Issues
 * 
 * Upload this file to your production server and run it via web browser
 * URL: https://yourdomain.com/quick_fix_shops.php
 */

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

echo "<h1>🔧 Quick Fix for Shop 404 Issues</h1>";
echo "<p>Running diagnostics and fixes...</p><br>";

try {
    // Get all shops
    $shops = \App\Models\AgentShop::with('user')->get();
    $issues = 0;
    $fixes = 0;
    
    echo "<h2>📋 Shop Analysis</h2>";
    
    foreach ($shops as $shop) {
        echo "<div style='border: 1px solid #ddd; margin: 10px 0; padding: 10px;'>";
        echo "<h3>Shop: {$shop->name} (@{$shop->username})</h3>";
        
        $hasIssues = false;
        
        // Check if shop is active
        if (!$shop->is_active) {
            echo "<p style='color: red;'>❌ Shop is inactive</p>";
            $shop->update(['is_active' => true]);
            echo "<p style='color: green;'>✅ Fixed: Activated shop</p>";
            $hasIssues = true;
            $fixes++;
        }
        
        // Check username format
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $shop->username)) {
            echo "<p style='color: red;'>❌ Invalid username format: {$shop->username}</p>";
            $cleanUsername = preg_replace('/[^a-zA-Z0-9_-]/', '_', $shop->username);
            
            // Ensure uniqueness
            $counter = 1;
            $originalClean = $cleanUsername;
            while (\App\Models\AgentShop::where('username', $cleanUsername)->where('id', '!=', $shop->id)->exists()) {
                $cleanUsername = $originalClean . $counter;
                $counter++;
            }
            
            $shop->update(['username' => $cleanUsername]);
            echo "<p style='color: green;'>✅ Fixed: Updated username to {$cleanUsername}</p>";
            $hasIssues = true;
            $fixes++;
        }
        
        // Check if user exists and is a dealer
        if (!$shop->user) {
            echo "<p style='color: red;'>❌ Shop owner not found</p>";
            $shop->delete();
            echo "<p style='color: green;'>✅ Fixed: Removed orphaned shop</p>";
            $hasIssues = true;
            $fixes++;
        } elseif ($shop->user->role !== 'dealer') {\n            echo "<p style='color: orange;'>⚠️ Shop owner is not a dealer (role: {$shop->user->role})</p>";
            if ($shop->user->role !== 'admin') {
                $shop->user->update(['role' => 'dealer']);
                echo "<p style='color: green;'>✅ Fixed: Updated user role to dealer</p>";
                $fixes++;
            }
            $hasIssues = true;
        }
        
        if (!$hasIssues) {
            echo "<p style='color: green;'>✅ No issues found</p>";
        } else {
            $issues++;
        }
        
        // Show test URL
        $testUrl = url("/shop/{$shop->username}");
        echo "<p><a href='{$testUrl}' target='_blank'>🔗 Test Shop URL</a></p>";
        
        echo "</div>";
    }
    
    // Check for dealers without shops
    echo "<h2>👥 Dealers Without Shops</h2>";
    $dealersWithoutShops = \App\Models\User::where('role', 'dealer')
        ->whereDoesntHave('agentShop')
        ->get();
        
    if ($dealersWithoutShops->count() > 0) {
        foreach ($dealersWithoutShops as $dealer) {
            echo "<div style='border: 1px solid #orange; margin: 10px 0; padding: 10px;'>";
            echo "<h3>Dealer: {$dealer->name}</h3>";
            echo "<p style='color: orange;'>⚠️ No shop found</p>";
            
            // Create a shop for the dealer
            $username = strtolower(str_replace(' ', '_', $dealer->name));
            $username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
            
            // Ensure uniqueness
            $counter = 1;
            $originalUsername = $username;
            while (\App\Models\AgentShop::where('username', $username)->exists()) {
                $username = $originalUsername . $counter;
                $counter++;
            }
            
            \App\Models\AgentShop::create([
                'user_id' => $dealer->id,
                'name' => $dealer->name . "'s Shop",
                'username' => $username,
                'is_active' => true,
                'color' => '#3B82F6'
            ]);
            
            echo "<p style='color: green;'>✅ Created shop: {$username}</p>";
            $testUrl = url("/shop/{$username}");
            echo "<p><a href='{$testUrl}' target='_blank'>🔗 Test New Shop URL</a></p>";
            
            $fixes++;
            echo "</div>";
        }
    } else {
        echo "<p style='color: green;'>✅ All dealers have shops</p>";
    }
    
    // Clear caches
    echo "<h2>🧹 Cache Clearing</h2>";
    try {
        \Artisan::call('config:cache');
        echo "<p>✅ Config cache cleared</p>";
        \Artisan::call('route:cache');
        echo "<p>✅ Route cache cleared</p>";
        \Artisan::call('view:cache');
        echo "<p>✅ View cache cleared</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ Cache clearing failed: " . $e->getMessage() . "</p>";
    }
    
    // Summary
    echo "<h2>📊 Summary</h2>";
    echo "<p><strong>Total shops:</strong> {$shops->count()}</p>";
    echo "<p><strong>Issues found:</strong> {$issues}</p>";
    echo "<p><strong>Fixes applied:</strong> {$fixes}</p>";
    
    if ($issues === 0) {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px;'>";
        echo "<h3 style='color: #155724;'>🎉 All Good!</h3>";
        echo "<p style='color: #155724;'>No issues found. All shops should be working correctly.</p>";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px;'>";
        echo "<h3 style='color: #856404;'>✅ Issues Fixed</h3>";
        echo "<p style='color: #856404;'>Found and fixed {$fixes} issues. Please test the affected shops.</p>";
        echo "</div>";
    }
    
    echo "<h2>🔍 Active Shops List</h2>";
    $activeShops = \App\Models\AgentShop::where('is_active', true)->with('user')->get();
    echo "<ul>";
    foreach ($activeShops as $shop) {
        $url = url("/shop/{$shop->username}");
        echo "<li><a href='{$url}' target='_blank'>{$shop->name} (@{$shop->username})</a> - Owner: {$shop->user->name}</li>";
    }
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px;'>";
    echo "<h3 style='color: #721c24;'>❌ Error</h3>";
    echo "<p style='color: #721c24;'>" . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><em>Fix completed at " . date('Y-m-d H:i:s') . "</em></p>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Test all shop URLs listed above</li>";
echo "<li>Check the Laravel logs for any remaining errors</li>";
echo "<li>Remove this file after confirming everything works</li>";
echo "</ol>";