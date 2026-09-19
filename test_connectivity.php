<?php

// Test script to simulate webhook from main site (port 8000) to this site (port 8001)

echo "Testing webhook connectivity from main site...\n";

// The webhook URL that should be configured on the main site
$webhookUrls = [
    'http://localhost:8001/api/webhook/status',
    'http://localhost:8001/webhook/status',
    'http://127.0.0.1:8001/api/webhook/status',
    'http://127.0.0.1:8001/webhook/status'
];

// Simulate the exact payload from main site logs
$payload = json_encode([
    'order_id' => 892,
    'new_status' => 'completed', 
    'user_id' => 14
]);

foreach ($webhookUrls as $url) {
    echo "\nTesting URL: $url\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: MainSite-Webhook/1.0'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    echo "Response Code: $httpCode\n";
    if ($error) {
        echo "Error: $error\n";
    } else {
        echo "Response: " . substr($response, 0, 200) . "\n";
    }
}

echo "\n=== Testing from the main site directory ===\n";
echo "Run this from your main site (port 8000):\n";
echo "curl -X POST http://localhost:8001/api/webhook/status -H \"Content-Type: application/json\" -d '$payload'\n";