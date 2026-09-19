<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BundlePortalOrderPusherService
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

        Log::info('Processing order for Bundle Portal push (Telecel/AT/Ishare/BigTime)', ['order_id' => $order->id]);

        $items = $order->products()->withPivot('quantity', 'price', 'beneficiary_number')->get();

        foreach ($items as $item) {
            $network = $this->getNetwork($item->name);

            if (!$network) {
                Log::info('Skipping non-supported product for Bundle Portal (Telecel/AT/BigTime)', [
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
                Log::warning('Could not determine package size for Bundle Portal (Telecel/AT)', [
                    'order_id'     => $order->id,
                    'product_name' => $item->name,
                    'quantity'     => $item->pivot->quantity,
                ]);
                continue;
            }

            $orderId = 'SD-' . $order->id . '-' . time();

            $payload = [
                'action'       => 'place_order',
                'network'      => $network,
                'recipient'    => $this->formatPhone($beneficiaryPhone),
                'package_size' => $packageSize,
                'order_id'     => $orderId,
            ];

            try {
                Log::info('Sending order to Bundle Portal (Telecel/AT)', [
                    'order_id'     => $order->id,
                    'network'      => $network,
                    'package_size' => $packageSize,
                    'phone'        => $beneficiaryPhone,
                    'bp_order_id'  => $orderId,
                ]);

                $response = Http::withHeaders([
                    'x-api-key'    => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])->timeout(30)->post($this->baseUrl, $payload);

                Log::info('Bundle Portal API Response (Telecel/AT)', [
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

                        Log::info('Order pushed to Bundle Portal successfully (Telecel/AT)', [
                            'order_id'  => $order->id,
                            'reference' => $reference,
                            'status'    => $data['data']['status'] ?? 'unknown',
                        ]);
                    } else {
                        $order->update(['api_status' => 'failed']);
                        Log::warning('Bundle Portal API returned failure (Telecel/AT)', [
                            'order_id' => $order->id,
                            'response' => $data,
                        ]);
                    }
                } else {
                    $order->update(['api_status' => 'failed']);
                    Log::error('Bundle Portal API call failed (Telecel/AT)', [
                        'order_id' => $order->id,
                        'status'   => $response->status(),
                        'response' => $response->body(),
                    ]);
                }
            } catch (\Exception $e) {
                $order->update(['api_status' => 'failed']);
                Log::error('Bundle Portal API Exception (Telecel/AT)', [
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
                'action'    => 'check_order',
                'reference' => $reference,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Bundle Portal order status check failed (Telecel/AT)', [
                'reference' => $reference,
                'status'    => $response->status(),
                'response'  => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('Bundle Portal order status check exception (Telecel/AT)', [
                'reference' => $reference,
                'message'   => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Maps product name to Bundle Portal network value.
     * AT Data (Instant) -> airteltigo (ishare)
     * AT (Big Packages) -> bigtime
     * Returns null for MTN (handled by BundlePortalMtnOrderPusherService).
     */
    private function getNetwork(string $productName): ?string
    {
        if (stripos($productName, 'telecel') !== false) {
            return 'telecel';
        }

        if (stripos($productName, 'at data') !== false) {
            return 'airteltigo';
        }

        if (stripos($productName, 'at (big') !== false) {
            return 'bigtime';
        }

        return null;
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
