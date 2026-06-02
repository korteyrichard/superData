<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CodeCraftMtnOrderPusherService
{
    private $apiKey;
    private $baseUrl = 'https://api.codecraftnetwork.com/api';

    public function __construct()
    {
        $this->apiKey = env('CODECRAFT_API_KEY', '');
        
        if (empty($this->apiKey)) {
            Log::error('CodeCraft API key is not configured');
            // Don't throw exception, just log and continue
            // The pushOrderToApi method will handle the empty key gracefully
        } else {
            Log::info('CodeCraft MTN Service initialized', ['api_key_length' => strlen($this->apiKey)]);
        }
    }

    public function pushOrderToApi(Order $order)
    {
        // Check if API key is available
        if (empty($this->apiKey)) {
            Log::error('CodeCraft MTN API key is not configured, skipping order push', ['order_id' => $order->id]);
            $order->update(['api_status' => 'failed']);
            return;
        }
        
        $apiEnabled = Setting::get('codecraft_mtn_api_enabled', 'true') === 'true';

        if (!$apiEnabled) {
            Log::info('CodeCraft MTN API is disabled, skipping order push', ['order_id' => $order->id]);
            $order->update(['api_status' => 'disabled']);
            return;
        }

        $items = $order->products()->withPivot('quantity', 'price', 'beneficiary_number')->get();

        foreach ($items as $item) {
            $beneficiaryPhone = $item->pivot->beneficiary_number;
            $gig = (int) filter_var($item->pivot->quantity, FILTER_SANITIZE_NUMBER_INT);
            $network = $this->getNetworkFromProduct($item->name);

            if (empty($beneficiaryPhone) || !$network || !$gig) {
                Log::warning('CodeCraft MTN: Missing required order data', [
                    'order_id' => $order->id,
                    'beneficiary' => $beneficiaryPhone,
                    'network' => $network,
                    'gig' => $gig
                ]);
                continue;
            }

            // Determine endpoint: bigtime vs regular
            $isBigTime = stripos($item->name, 'big') !== false;
            $endpoint = $isBigTime
                ? $this->baseUrl . '/special.php'
                : $this->baseUrl . '/initiate.php';

            $payload = [
                'recipient_number' => $this->formatPhone($beneficiaryPhone),
                'gig' => (string) $gig,
                'network' => 'MTN'
            ];

            Log::info('Sending MTN order to CodeCraft API', [
                'endpoint' => $endpoint, 
                'payload' => $payload,
                'api_key_present' => !empty($this->apiKey),
                'api_key_length' => strlen($this->apiKey),
                'is_big_time' => $isBigTime
            ]);

            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'x-api-key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ])
                    ->post($endpoint, $payload);

                $responseData = $response->json();

                Log::info('CodeCraft MTN API Response', [
                    'status_code' => $response->status(),
                    'response' => $responseData,
                    'headers' => $response->headers()
                ]);

                // Handle successful response
                if ($response->status() == 200 && isset($responseData['reference_id'])) {
                    $order->update([
                        'reference_id' => $responseData['reference_id'],
                        'api_status' => 'success'
                    ]);
                    Log::info('MTN order sent to CodeCraft successfully', ['reference_id' => $responseData['reference_id']]);
                } 
                // Handle special endpoint 404 - fallback to regular endpoint
                elseif ($isBigTime && $response->status() == 404) {
                    Log::warning('Special endpoint not available, falling back to regular endpoint', [
                        'order_id' => $order->id,
                        'original_endpoint' => $endpoint
                    ]);
                    
                    // Retry with regular endpoint
                    $fallbackEndpoint = $this->baseUrl . '/initiate.php';
                    $fallbackResponse = Http::timeout(30)
                        ->withHeaders([
                            'x-api-key' => $this->apiKey,
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json'
                        ])
                        ->post($fallbackEndpoint, $payload);
                    
                    $fallbackData = $fallbackResponse->json();
                    
                    if ($fallbackResponse->status() == 200 && isset($fallbackData['reference_id'])) {
                        $order->update([
                            'reference_id' => $fallbackData['reference_id'],
                            'api_status' => 'success'
                        ]);
                        Log::info('MTN order sent to CodeCraft successfully via fallback', [
                            'reference_id' => $fallbackData['reference_id'],
                            'fallback_endpoint' => $fallbackEndpoint
                        ]);
                    } else {
                        $order->update(['api_status' => 'failed']);
                        Log::error('CodeCraft MTN API fallback also failed', [
                            'status_code' => $fallbackResponse->status(),
                            'response' => $fallbackData
                        ]);
                    }
                }
                else {
                    $order->update(['api_status' => 'failed']);
                    
                    // Enhanced error logging
                    $errorMessage = $responseData['message'] ?? 'Unknown error';
                    $statusCode = $responseData['status'] ?? $response->status();
                    
                    Log::error('CodeCraft MTN API Error', [
                        'status_code' => $statusCode,
                        'message' => $errorMessage,
                        'full_response' => $responseData,
                        'order_id' => $order->id,
                        'endpoint' => $endpoint,
                        'payload_sent' => $payload
                    ]);
                    
                    // Handle specific error cases
                    if ($statusCode == 401) {
                        Log::error('CodeCraft API Authentication Failed - Check API key');
                    } elseif ($statusCode == 402) {
                        Log::error('CodeCraft Agent Not Found - Contact CodeCraft support');
                    }
                }
            } catch (\Exception $e) {
                $order->update(['api_status' => 'failed']);
                Log::error('CodeCraft MTN API Exception', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                    'endpoint' => $endpoint ?? 'unknown',
                    'payload' => $payload ?? []
                ]);
            }
        }
    }

    private function formatPhone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) == 9) {
            return '0' . $phone;
        }
        return $phone;
    }

    private function getNetworkFromProduct($productName)
    {
        if (stripos($productName, 'mtn') !== false) {
            return 'MTN';
        }
        return null;
    }
}