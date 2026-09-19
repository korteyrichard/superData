<?php

// Test webhook script - Run this to test your webhook endpoint
// Usage: php test_webhook.php

$webhookUrl = 'http://localhost:8001/api/webhook/status';
$secret = 'fd93ce342f0b1782bed029481943428f';

// Test payload similar to what main site sends
$payload = json_encode([
    'order_id' => 13,  // Use actual order ID from database
    'new_status' => 'completed',
    'user_id' => 14,
    'reference_id' => '892',  // This matches what's in database
    'timestamp' => date('c')
]);

// Generate signature
$signature = hash_hmac('sha256', $payload, $secret);

// Prepare request headers
$headers = [
    'Content-Type: application/json',
    'X-Webhook-Signature: ' . $signature
];

echo "Testing webhook endpoint...\n";
echo "URL: $webhookUrl\n";
echo "Payload: $payload\n";
echo "Signature: $signature\n\n";

// Make the request
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $webhookUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

echo "Response Code: $httpCode\n";
if ($error) {
    echo "cURL Error: $error\n";
}
echo "Response: $response\n";

// Also test the test endpoint
echo "\n\nTesting the test endpoint...\n";
$testUrl = 'http://localhost:8001/api/webhook/test';
$testResponse = file_get_contents($testUrl);
echo "Test endpoint response: $testResponse\n";