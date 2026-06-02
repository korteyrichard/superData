<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FailedOrderRetryService
{
    private $orderPusherService;

    public function __construct(OrderPusherService $orderPusherService)
    {
        $this->orderPusherService = $orderPusherService;
    }

    public function retryFailedOrders()
    {
        // Check if API is enabled
        $apiEnabled = Setting::get('api_enabled', 'true') === 'true';
        
        if (!$apiEnabled) {
            Log::info('OrderPusherService API is disabled, skipping failed order retry');
            return;
        }
        
        Log::info('Starting failed order retry process for OrderPusherService (MTN orders)');
        
        $currentHour = Carbon::now()->startOfHour();
        $nextHour = Carbon::now()->startOfHour()->addHour();
        
        // Debug: Check total orders this hour
        $totalOrdersThisHour = Order::whereBetween('created_at', [$currentHour, $nextHour])->count();
        Log::info('Debug: Total orders this hour', ['count' => $totalOrdersThisHour, 'hour' => $currentHour->format('Y-m-d H:i')]);
        
        // Debug: Check failed orders this hour
        $failedOrdersThisHour = Order::where('api_status', 'failed')
            ->whereBetween('created_at', [$currentHour, $nextHour])->count();
        Log::info('Debug: Failed orders this hour', ['count' => $failedOrdersThisHour]);
        
        // Debug: Check processing orders this hour
        $processingOrdersThisHour = Order::where('status', 'processing')
            ->whereBetween('created_at', [$currentHour, $nextHour])->count();
        Log::info('Debug: Processing orders this hour', ['count' => $processingOrdersThisHour]);
        
        // Get failed orders from this hour that have MTN products and are in processing status
        $failedOrders = Order::where('api_status', 'failed')
            ->where('status', 'processing')
            ->whereBetween('created_at', [$currentHour, $nextHour])
            ->whereHas('products', function ($query) {
                $query->where('name', 'like', '%mtn%');
            })
            ->get();
        
        Log::info('Found failed MTN orders in processing status this hour', ['count' => $failedOrders->count()]);
        
        foreach ($failedOrders as $order) {
            $this->processFailedOrder($order, $currentHour, $nextHour);
        }
        
        Log::info('Completed failed order retry process for OrderPusherService');
    }

    private function processFailedOrder(Order $order, $currentHour, $nextHour)
    {
        $items = $order->products()->where('name', 'like', '%mtn%')
            ->withPivot('beneficiary_number')->get();
        
        foreach ($items as $item) {
            $beneficiaryNumber = $item->pivot->beneficiary_number;
            
            if (empty($beneficiaryNumber)) {
                continue;
            }
            
            // Check if there's already a successful MTN order for this beneficiary this hour
            $existingSuccessfulOrder = Order::where('api_status', 'success')
                ->whereBetween('created_at', [$currentHour, $nextHour])
                ->whereHas('products', function ($query) use ($beneficiaryNumber) {
                    $query->where('name', 'like', '%mtn%')
                          ->where('order_product.beneficiary_number', $beneficiaryNumber);
                })
                ->exists();
            
            if ($existingSuccessfulOrder) {
                Log::info('Skipping retry - successful MTN order exists for beneficiary this hour', [
                    'order_id' => $order->id,
                    'beneficiary' => $beneficiaryNumber
                ]);
                continue;
            }
            
            // No successful MTN order exists this hour, retry this order
            Log::info('Retrying failed MTN order', [
                'order_id' => $order->id,
                'beneficiary' => $beneficiaryNumber,
                'status' => $order->status
            ]);
            
            $this->orderPusherService->pushOrderToApi($order);
            break; // Only process once per order
        }
    }
}