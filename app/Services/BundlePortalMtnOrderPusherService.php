<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BundlePortalMtnOrderPusherService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.bundleportal.base_url', 'https://api.bundleportal.com/v1');
        $this->apiKey  = config('services.bundleportal.api_key', '');
    }

    public function pushOrderToApi(Order $order): void
    {
        if (empty($this->apiKey)) {
            Log::error('Bundle Portal API key is not configured', ['order_id' => $order->id]);
            $order->update(['api_status' => 'failed']);
            return;
        }

        Log::info('Processing order for Bundle Portal MTN push', ['order_id' => $order->id]);

        $items = $order->products()->withPivot('quantity', 'price', 'beneficiary_number')->get();

        foreach ($items as $item) {
            if (!$this->isMtnProduct($item->name)) {
                Log::info('Skipping non-MTN product for Bundle Portal', [
                    'product'  => $item->name,
                    'order_id' => $order->id,
                ]);
                continue;
            }

            $beneficiaryPhone = $item->pivot->beneficiary_number ?? $order->beneficiary_number;

            if (empty($beneficiaryPhone)) {
                Log::warning('Missing beneficiary number for Bundle Portal push', ['order_id' => $order->id]);
                continue;
            }

            $packageSize = $this->getPackageSizeFromItem($item);

            if ($packageSize === null) {
                Log::warning('Could not determine package size for Bundle Portal', [
                    'order_id'     => $order->id,
                    'product_name' => $item->name,
                    'quantity'     => $item->pivot->quantity,
                ]);
                continue;
            }

            $orderId = 'SD-' . $order->id . '-' . time();

            $payload = [
                'action'       => 'place_order',
                'network'      => 'mtn',
                'recipient'    => $this->formatPhone($beneficiaryPhone),
                'package_size' => $packageSize,
                'order_id'     => $orderId,
            ];

            try {
                Log::info('Sending MTN order to Bundle Portal', [
                    'order_id'     => $order->id,
                    'package_size' => $packageSize,
                    'phone'        => $beneficiaryPhone,
                    'bp_order_id'  => $orderId,
                ]);

                $response = Http::withHeaders([
                    'x-api-key'    => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])->timeout(30)->post($this->baseUrl, $payload);

                Log::info('Bundle Portal API Response', [
                    'order_id' => $order->id,
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                ]);

                if ($response->successful()) {
                    $data = $response->json();

                    if (isset($data['success']) && $data['success'] === true) {
                        $reference = $data['data']['reference'] ?? $data['data']['order_id'] ?? $orderId;

                        $order->update([
                            'reference_id' => $reference,
                            'api_status'   => 'success',
                        ]);

                        Log::info('Order pushed to Bundle Portal successfully', [
                            'order_id'  => $order->id,
                            'reference' => $reference,
                            'status'    => $data['data']['status'] ?? 'unknown',
                        ]);
                    } else {
                        $order->update(['api_status' => 'failed']);
                        Log::warning('Bundle Portal API returned failure', [
                            'order_id' => $order->id,
                            'response' => $data,
                        ]);
                    }
                } else {
                    $order->update(['api_status' => 'failed']);
                    Log::error('Bundle Portal API call failed', [
                        'order_id' => $order->id,
                        'status'   => $response->status(),
                        'response' => $response->body(),
                    ]);
                }
            } catch (\Exception $e) {
                $order->update(['api_status' => 'failed']);
                Log::error('Bundle Portal API Exception', [
                    'order_id' => $order->id,
                    'message'  => $e->getMessage(),
                ]);
            }
        }
    }

    public function checkOrderStatus(string $reference): ?array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key'    => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])->timeout(30)->post($this->baseUrl, [
                'action'    => 'check_status',
                'reference' => $reference,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 429) {
                $data = $response->json();
                Log::warning('Bundle Portal MTN status check is rate limited', [
                    'reference' => $reference,
                    'retry_after' => $data['retry_after'] ?? null,
                    'response' => $data,
                ]);
                return null;
            }

            Log::warning('Bundle Portal order status check failed', [
                'reference' => $reference,
                'status'    => $response->status(),
                'response'  => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Bundle Portal order status check exception', [
                'reference' => $reference,
                'message'   => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function isMtnProduct(string $productName): bool
    {
        return stripos($productName, 'mtn') !== false;
    }

    private function getPackageSizeFromItem($item): ?int
    {
        $quantity = strtolower((string) $item->pivot->quantity);
        $name     = strtolower($item->name);

        // Numeric quantity treated as GB value
        if (is_numeric($quantity)) {
            return (int) $quantity;
        }

        // Extract GB value from quantity string e.g. "5gb", "5 gb"
        if (preg_match('/(\d+(?:\.\d+)?)\s*gb/', $quantity, $matches)) {
            return (int) $matches[1];
        }

        // Fall back to product name
        if (preg_match('/(\d+(?:\.\d+)?)\s*gb/', $name, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($phone) === 9) {
            return '0' . $phone;
        }

        return $phone;
    }
}
