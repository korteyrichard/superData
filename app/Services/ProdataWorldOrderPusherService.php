<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProdataWorldOrderPusherService
{
    private $apiKey;
    private $baseUrl = 'https://www.prodataworld.com';
    private const MTN_NETWORK_ID = 5;

    public function __construct()
    {
        $this->apiKey = env('PRODATAWORLD_API_KEY', '');
    }

    public function pushOrderToApi(Order $order)
    {
        // Check if ProdataWorld API is enabled
        $apiEnabled = Setting::get('prodataworld_api_enabled', 'false') === 'true';
        
        if (!$apiEnabled) {
            Log::info('ProdataWorld API is disabled, skipping order push', ['order_id' => $order->id]);
            $order->update(['api_status' => 'disabled']);
            return;
        }

        Log::info('Processing order for ProdataWorld API push', ['order_id' => $order->id]);
        
        // Check if order is MTN only
        if (!$this->isMtnOrder($order)) {
            Log::info('Non-MTN order detected, skipping ProdataWorld processing', ['order_id' => $order->id]);
            return;
        }
        
        $items = $order->products()->withPivot('quantity', 'price', 'beneficiary_number')->get();
        Log::info('Order has items', ['count' => $items->count()]);
        
        $processedItems = 0;

        foreach ($items as $item) {
            Log::info('Processing item', ['name' => $item->name]);
            
            $beneficiaryPhone = $item->pivot->beneficiary_number;
            // Extract numeric value from quantity (e.g., "2GB" -> 2)
            $size = $this->extractDataSize($item->pivot->quantity);
            
            if (empty($beneficiaryPhone) || !$size) {
                Log::warning('Missing required order data', [
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'beneficiary' => $beneficiaryPhone,
                    'size' => $size
                ]);
                continue;
            }
            
            $processedItems++;

            $payload = [
                'beneficiary_number' => $this->formatPhone($beneficiaryPhone),
                'network_id' => self::MTN_NETWORK_ID,
                'size' => $size . 'GB'
            ];
            
            $endpoint = $this->baseUrl . '/api/v1/normal-orders';
            
            Log::info('Sending to ProdataWorld API', ['endpoint' => $endpoint, 'payload' => $payload]);

            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ])
                    ->post($endpoint, $payload);
                
                $statusCode = $response->status();
                $responseData = $response->json();
                
                Log::info('ProdataWorld API Response', [
                    'status_code' => $statusCode,
                    'response' => $responseData
                ]);

                if ($response->successful()) {
                    // Try multiple possible paths for reference ID
                    $referenceId = $responseData['reference_id'] ?? 
                                   $responseData['id'] ?? 
                                   $responseData['order']['reference_id'] ?? 
                                   $responseData['order']['id'] ?? 
                                   $responseData['data']['reference_id'] ?? 
                                   $responseData['data']['id'] ?? 
                                   $responseData['data'][0]['reference_id'] ?? 
                                   $responseData['data'][0]['id'] ?? null;
                    
                    if ($referenceId) {
                        $order->update([
                            'reference_id' => $referenceId,
                            'api_status' => 'success'
                        ]);
                        
                        Log::info('Order sent successfully to ProdataWorld', ['reference_id' => $referenceId]);
                    } else {
                        $order->update(['api_status' => 'failed']);
                        Log::error('ProdataWorld API Error', [
                            'status_code' => $statusCode,
                            'message' => 'No reference ID found in response',
                            'response' => $responseData
                        ]);
                    }
                } else {
                    $order->update(['api_status' => 'failed']);
                    $message = $responseData['message'] ?? 'Unknown error';
                    Log::error('ProdataWorld API Error', [
                        'status_code' => $statusCode,
                        'message' => $message
                    ]);
                }

            } catch (\Exception $e) {
                $order->update(['api_status' => 'failed']);
                Log::error('ProdataWorld API Exception', [
                    'message' => $e->getMessage()
                ]);
            }
        }
        
        if ($processedItems === 0) {
            Log::info('No items were processed for order, keeping status as disabled', ['order_id' => $order->id]);
        }
    }
    
    private function isMtnOrder($order)
    {
        $network = strtolower($order->network ?? '');
        return stripos($network, 'mtn') !== false;
    }
    
    private function formatPhone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
            return $phone;
        }
        
        if (strlen($phone) == 9) {
            return '0' . $phone;
        }
        
        return $phone;
    }
    
    private function extractDataSize($size)
    {
        // Extract numeric value from size string (e.g., "2GB" -> 2, "1" -> 1)
        $numericValue = (int)filter_var($size, FILTER_SANITIZE_NUMBER_INT);
        return $numericValue > 0 ? $numericValue : null;
    }
    
    public function checkOrderStatus($referenceId)
    {
        $endpoint = $this->baseUrl . '/api/v1/transactions/' . $referenceId;
            
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json'
                ])
                ->get($endpoint);
                
            $responseData = $response->json();
            
            Log::info('Order status check response', [
                'reference_id' => $referenceId,
                'response' => $responseData
            ]);
            
            return $responseData;
            
        } catch (\Exception $e) {
            Log::error('Order status check failed', [
                'reference_id' => $referenceId,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}
