<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use App\Services\CommissionService;
use App\Services\SmsService;
use App\Services\DataFlowOrderPusherService;

class DataFlowOrderStatusSyncService
{
    private $dataFlowService;
    private $smsService;
    private $commissionService;

    public function __construct()
    {
        $this->dataFlowService = new DataFlowOrderPusherService();
        $this->smsService = new SmsService();
        $this->commissionService = new CommissionService();
    }

    public function syncOrderStatuses()
    {
        // Get MTN orders that were pushed to DataFlow (have a reference_id)
        $processingOrders = Order::whereIn('status', ['pending', 'processing'])
            ->whereNotNull('reference_id')
            ->where('network', 'like', '%mtn%')
            ->get();
        
        Log::info('Found ' . $processingOrders->count() . ' MTN orders to check for DataFlow status sync');
        
        foreach ($processingOrders as $order) {
            // Check if this referenceId looks like a DataFlow ID (numeric)
            if (!is_numeric($order->reference_id)) {
                // Skipping non-DataFlow IDs
                continue;
            }

            try {
                $this->syncDataFlowOrderStatus($order);
            } catch (\Exception $e) {
                Log::error("Failed to sync DataFlow status for order {$order->id}: {$e->getMessage()}");
            }
        }
    }

    private function syncDataFlowOrderStatus($order)
    {
        $referenceId = $order->reference_id;
        
        try {
            $responseData = $this->dataFlowService->checkOrderStatus($referenceId);

            if ($responseData && isset($responseData['success']) && $responseData['success'] === true && isset($responseData['data'])) {
                $orderData = $responseData['data'];
                $orderStatus = $orderData['status'] ?? '';
                
                $newStatus = $this->mapDataFlowStatus($orderStatus);
                
                if ($newStatus && $newStatus !== $order->status) {
                    $order->update(['status' => $newStatus]);
                    
                    Log::info('DataFlow order status updated', [
                        'order_id' => $order->id,
                        'old_status' => $order->getOriginal('status'),
                        'new_status' => $newStatus,
                        'external_status' => $orderStatus
                    ]);

                    if ($newStatus === 'completed' && $order->user && $order->user->phone) {
                        $message = "Your MTN order #{$order->id} to {$order->beneficiary_number} has been completed successfully. Thank you for using SuperData!";
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
            Log::error('DataFlow Status check error for order ' . $order->id . ': ' . $e->getMessage());
        }
    }

    private function mapDataFlowStatus($orderStatus)
    {
        $statusMap = [
            'pending' => 'processing',
            'processing' => 'processing',
            'completed' => 'completed',
            'failed' => 'cancelled',
        ];

        return $statusMap[$orderStatus] ?? null;
    }
}