<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Mtn3BundlePortalOrderPusherService
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
            Log::error('MTN3 Bundle Portal API key is not configured', ['order_id' => $order->id]);
            $order->update(['api_status' => 'failed']);
            return;
        }

        Log::info('Processing order for MTN3 Bundle Portal push', ['order_id' => $order->id]);

        $items = $order->products()->withPivot('quantity', 'price', 'beneficiary_number')->get();

        foreach ($items as $item) {
            if (!$this->isMtn3Product($item->name)) {
                Log::info('Skipping non-MTN3 product for Bundle Portal', [
                    'product'  => $item->name,
                    'order_id' => $order->id,
                ]);
                continue;
            }

            $beneficiaryPhone = $item->pivot->beneficiary_number ?? $order->beneficiary_number;

            if (empty($beneficiaryPhone)) {
                Log::warning('Missing beneficiary number for MTN3 Bundle Portal push', ['order_id' => $order->id]);
                continue;
            }

            $packageSize = $this->getPackageSizeFromItem($item);

            if ($packageSize === null) {
                Log::warning('Could not determine package size for MTN3 Bundle Portal', [
                    'order_id'     => $order->id,
                    'product_name' => $item->name,
                    'quantity'     => $item->pivot->quantity,
                ]);
                continue;
            }

            $orderId = 'SD-MTN3-' . $order->id . '-' . time();

            $payload = [
                'action'       => 'place_order',
                'network'      => 'mtn_3',
                'recipient'    => $this->formatPhone($beneficiaryPhone),
                'package_size' => $packageSize,
                'order_id'     => $orderId,
            ];

            try {
                Log::info('Sending MTN3 order to Bundle Portal', [
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

                Log::info('MTN3 Bundle Portal API Response', [
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

                        Log::info('MTN3 order pushed to Bundle Portal successfully', [
                            'order_id'  => $order->id,
                            'reference' => $reference,
                            'status'    => $data['data']['status'] ?? 'unknown',
                        ]);
                    } else {
                        $order->update(['api_status' => 'failed']);
                        Log::warning('MTN3 Bundle Portal API returned failure', [
                            'order_id' => $order->id,
                            'response' => $data,
                        ]);
                    }
                } else {
                    $order->update(['api_status' => 'failed']);
                    Log::error('MTN3 Bundle Portal API call failed', [
                        'order_id' => $order->id,
                        'status'   => $response->status(),
                        'response' => $response->body(),
                    ]);
                }
            } catch (\Exception $e) {
                $order->update(['api_status' => 'failed']);
                Log::error('MTN3 Bundle Portal API Exception', [
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
                Log::warning('MTN3 Bundle Portal status check is rate limited', [
                    'reference' => $reference,
                    'retry_after' => $data['retry_after'] ?? null,
                    'response' => $data,
                ]);
                return null;
            }

            Log::warning('MTN3 Bundle Portal order status check failed', [
                'reference' => $reference,
                'status'    => $response->status(),
                'response'  => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('MTN3 Bundle Portal order status check exception', [
                'reference' => $reference,
                'message'   => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function isMtn3Product(string $productName): bool
    {
        $normalizedName = strtolower(trim($productName));
        $normalizedName = preg_replace('/\s+/', ' ', $normalizedName);

        return $normalizedName === 'mtn instant'
            || stripos($normalizedName, 'mtn3') !== false
            || stripos($normalizedName, 'mtn 3') !== false
            || preg_match('/\bmtn\b.*\binstant\b|\binstant\b.*\bmtn\b/', $normalizedName) === 1;
    }

    private function getPackageSizeFromItem($item): ?int
    {
        $quantity = strtolower((string) $item->pivot->quantity);
        $name     = strtolower($item->name);

        if (is_numeric($quantity)) {
            return (int) $quantity;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*gb/', $quantity, $matches)) {
            return (int) $matches[1];
        }

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
