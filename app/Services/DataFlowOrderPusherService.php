<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DataFlowOrderPusherService
{
    private $baseUrl;
    private $apiKey;

    public function __construct()
    {
        $this->baseUrl = env('DATAFLOW_BASE_URL', 'https://dataflowghana.com/api/v1');
        $this->apiKey = env('DATAFLOW_API_KEY');
    }

    public function pushOrderToApi(Order $order)
    {
        if (empty($this->apiKey)) {
            Log::error('DataFlow API key is not configured, skipping order push', ['order_id' => $order->id]);
            $order->update(['api_status' => 'failed']);
            return;
        }
        
        $apiEnabled = Setting::get('dataflow_api_enabled', 'false') === 'true';

        if (!$apiEnabled) {
            Log::info('DataFlow API is disabled, skipping order push', ['order_id' => $order->id]);
            $order->update(['api_status' => 'disabled']);
            return;
        }

        Log::info('Processing order for DataFlow API push', ['order_id' => $order->id]);
        
        $items = $order->products()->withPivot('quantity', 'price', 'beneficiary_number')->get();
        
        foreach ($items as $item) {
            // Only handle MTN orders
            if (!$this->isMtnProduct($item->name)) {
                Log::info('Skipping non-MTN product for DataFlow', ['product' => $item->name, 'order_id' => $order->id]);
                continue;
            }
            
            $beneficiaryPhone = $item->pivot->beneficiary_number ?? $order->beneficiary_number;
            
            if (empty($beneficiaryPhone)) {
                Log::warning('Missing beneficiary number for DataFlow push', ['order_id' => $order->id]);
                continue;
            }

            $size = $this->getSizeFromItem($item);
            
            if (!$size) {
                Log::warning('Could not determine size for DataFlow', [
                    'order_id' => $order->id,
                    'product_name' => $item->name,
                    'quantity' => $item->pivot->quantity
                ]);
                continue;
            }

            $endpoint = $this->baseUrl . '/normal-orders';
            $payload = [
                'beneficiary_number' => $this->formatPhone($beneficiaryPhone),
                'network_id' => 13, // DataFlow MTN network ID
                'size' => $size
            ];

            try {
                Log::info('Sending MTN order to DataFlow API', [
                    'order_id' => $order->id,
                    'size' => $size,
                    'phone' => $beneficiaryPhone
                ]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])->timeout(30)->post($endpoint, $payload);

                Log::info('DataFlow API Response', [
                    'order_id' => $order->id,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                if ($response->successful()) {
                    $responseData = $response->json();
                    
                    if (isset($responseData['order']['reference_id'])) {
                        $externalId = $responseData['order']['reference_id'];
                        
                        $order->update([
                            'reference_id' => $externalId,
                            'api_status' => 'success'
                        ]);
                        
                        Log::info('Order pushed to DataFlow successfully', [
                            'order_id' => $order->id,
                            'external_id' => $externalId
                        ]);
                    } else {
                        $order->update(['api_status' => 'failed']);
                        Log::warning('DataFlow API response missing reference_id', [
                            'order_id' => $order->id,
                            'response' => $responseData
                        ]);
                    }
                } else {
                    $order->update(['api_status' => 'failed']);
                    Log::error('DataFlow API call failed', [
                        'order_id' => $order->id,
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);
                }

            } catch (\Exception $e) {
                $order->update(['api_status' => 'failed']);
                Log::error('DataFlow API Exception', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage()
                ]);
            }
        }
    }
    
    public function checkOrderStatus($externalId)
    {
        $endpoint = $this->baseUrl . '/transactions/' . $externalId;
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->timeout(30)->get($endpoint);
            
            if ($response->successful()) {
                return $response->json();
            }
            
            Log::warning('DataFlow order status check failed', [
                'external_id' => $externalId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            
            return null;
        } catch (\Exception $e) {
            Log::error('DataFlow order status check exception', [
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
    
    private function getSizeFromItem($item)
    {
        $quantity = strtolower((string)$item->pivot->quantity);
        $name = strtolower($item->name);
        
        // Try to extract from quantity first
        if (preg_match('/(\d+(?:\.\d+)?)\s*(gb|mb)/', $quantity, $matches)) {
            return $matches[1] . strtoupper($matches[2]);
        }
        
        if (is_numeric($quantity)) {
             return $quantity . "GB";
        }
        
        // Try to extract from name
        if (preg_match('/(\d+(?:\.\d+)?)\s*(gb|mb)/', $name, $matches)) {
            return $matches[1] . strtoupper($matches[2]);
        }
        
        return null;
    }
}