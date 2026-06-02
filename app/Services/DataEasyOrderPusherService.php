<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DataEasyOrderPusherService
{
    private $baseUrl;
    private $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.dataeasy.base_url', 'https://dataeasy.onrender.com/api/v1');
        $this->apiKey = config('services.dataeasy.api_key');
    }

    public function pushOrderToApi(Order $order)
    {
        Log::info('Processing order for DataEasy API push', ['order_id' => $order->id]);
        
        $items = $order->products()->withPivot('quantity', 'price', 'beneficiary_number')->get();
        
        foreach ($items as $item) {
            // Only handle MTN orders
            if (!$this->isMtnProduct($item->name)) {
                Log::info('Skipping non-MTN product for DataEasy', ['product' => $item->name, 'order_id' => $order->id]);
                continue;
            }
            
            $beneficiaryPhone = $item->pivot->beneficiary_number ?? $order->beneficiary_number;
            
            if (empty($beneficiaryPhone)) {
                Log::warning('Missing beneficiary number for DataEasy push', ['order_id' => $order->id]);
                continue;
            }

            $packageId = $this->getPackageIdFromItem($item);
            
            if (!$packageId) {
                Log::warning('Could not determine packageId for DataEasy', [
                    'order_id' => $order->id,
                    'product_name' => $item->name,
                    'quantity' => $item->pivot->quantity
                ]);
                continue;
            }

            $endpoint = $this->baseUrl . '/orders';
            $payload = [
                'network' => 'MTN',
                'items' => [
                    [
                        'packageId' => $packageId,
                        'phoneNumber' => $this->formatPhone($beneficiaryPhone)
                    ]
                ]
            ];

            try {
                Log::info('Sending MTN order to DataEasy API', [
                    'order_id' => $order->id,
                    'packageId' => $packageId,
                    'phone' => $beneficiaryPhone
                ]);

                $response = Http::withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ])->timeout(30)->post($endpoint, $payload);

                Log::info('DataEasy API Response', [
                    'order_id' => $order->id,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                if ($response->successful()) {
                    $responseData = $response->json();
                    
                    if (isset($responseData['success']) && $responseData['success'] === true && isset($responseData['order']['id'])) {
                        $externalId = $responseData['order']['id'];
                        
                        $order->update([
                            'reference_id' => $externalId,
                            'api_status' => 'success'
                        ]);
                        
                        Log::info('Order pushed to DataEasy successfully', [
                            'order_id' => $order->id,
                            'external_id' => $externalId
                        ]);
                    } else {
                        $order->update(['api_status' => 'failed']);
                        Log::warning('DataEasy API response failed', [
                            'order_id' => $order->id,
                            'response' => $responseData
                        ]);
                    }
                } else {
                    $order->update(['api_status' => 'failed']);
                    Log::error('DataEasy API call failed', [
                        'order_id' => $order->id,
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);
                }

            } catch (\Exception $e) {
                $order->update(['api_status' => 'failed']);
                Log::error('DataEasy API Exception', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage()
                ]);
            }
        }
    }
    
    public function checkOrderStatus($externalId)
    {
        $endpoint = $this->baseUrl . '/orders/' . $externalId;
        
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json'
            ])->timeout(30)->get($endpoint);
            
            if ($response->successful()) {
                return $response->json();
            }
            
            Log::warning('DataEasy order status check failed', [
                'external_id' => $externalId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            
            return null;
        } catch (\Exception $e) {
            Log::error('DataEasy order status check exception', [
                'external_id' => $externalId,
                'message' => $e->getMessage()
            ]);
            return null;
        }
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
    
    private function isMtnProduct($productName)
    {
        $productName = strtolower($productName);
        return stripos($productName, 'mtn') !== false;
    }
    
    private function getPackageIdFromItem($item)
    {
        $quantity = strtolower((string)$item->pivot->quantity);
        $name = strtolower($item->name);
        
        // Try to extract from quantity first
        if (preg_match('/(\d+(?:\.\d+)?)\s*(gb|mb)/', $quantity, $matches)) {
            return "mtn-" . $matches[1] . $matches[2];
        }
        
        if (is_numeric($quantity)) {
             return "mtn-" . $quantity . "gb";
        }
        
        // Try to extract from name
        if (preg_match('/(\d+(?:\.\d+)?)\s*(gb|mb)/', $name, $matches)) {
            return "mtn-" . $matches[1] . $matches[2];
        }
        
        return null;
    }
}
