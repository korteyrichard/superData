<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use App\Services\CommissionService;
use App\Services\SmsService;
use App\Services\DataEasyOrderPusherService;

class DataEasyOrderStatusSyncService
{
    private $dataEasyService;
    private $smsService;
    private $commissionService;

    public function __construct()
    {
        $this->dataEasyService = new DataEasyOrderPusherService();
        $this->smsService = new SmsService();
        $this->commissionService = new CommissionService();
    }

    public function syncOrderStatuses()
    {
        // Get MTN orders that were pushed to DataEasy (have a UUID reference_id)
        $processingOrders = Order::whereIn('status', ['pending', 'processing'])
            ->whereNotNull('reference_id')
            ->where('network', 'like', '%mtn%')
            ->get();
        
        Log::info('Found ' . $processingOrders->count() . ' MTN orders to check for DataEasy status sync');
        
        foreach ($processingOrders as $order) {
            // Check if this referenceId looks like a DataEasy UUID (e.g. 8-4-4-4-12 chars)
            if (strlen($order->reference_id) < 20 && is_numeric($order->reference_id)) {
                // Skipping DataMaster or other numeric IDs
                continue;
            }

            try {
                $this->syncDataEasyOrderStatus($order);
            } catch (\Exception $e) {
                Log::error("Failed to sync DataEasy status for order {$order->id}: {$e->getMessage()}");
            }
        }
    }

    private function syncDataEasyOrderStatus($order)
    {
        $referenceId = $order->reference_id;
        
        try {
            $responseData = $this->dataEasyService->checkOrderStatus($referenceId);

            if ($responseData && isset($responseData['success']) && $responseData['success'] === true && isset($responseData['order'])) {
                $orderData = $responseData['order'];
                $deliveryStatus = $orderData['deliveryStatus'] ?? '';
                
                $newStatus = $this->mapDataEasyStatus($deliveryStatus);
                
                if ($newStatus && $newStatus !== $order->status) {
                    $order->update(['status' => $newStatus]);
                    
                    Log::info('DataEasy order status updated', [
                        'order_id' => $order->id,
                        'old_status' => $order->getOriginal('status'),
                        'new_status' => $newStatus,
                        'external_status' => $deliveryStatus
                    ]);

                    if ($newStatus === 'completed' && $order->user && $order->user->phone) {
                        $message = "Your MTN order #{$order->id} to {$order->beneficiary_number} has been completed successfully. Thank you for using DF-Ghana!";
                        $this->smsService->sendSms($order->user->phone, $message);
                        
                        // Also make commission available
                        $this->commissionService->makeCommissionAvailable($order);
                    }
                    
                    if ($newStatus === 'cancelled') {
                        $this->commissionService->reverseCommission($order);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('DataEasy Status check error for order ' . $order->id . ': ' . $e->getMessage());
        }
    }

    private function mapDataEasyStatus($deliveryStatus)
    {
        $statusMap = [
            'Pending' => 'processing',
            'Processing' => 'processing',
            'Delivered' => 'completed',
            'Failed' => 'cancelled',
        ];

        return $statusMap[$deliveryStatus] ?? null;
    }
}
