<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class BundlePortalOrderStatusSyncService
{
    private BundlePortalOrderPusherService $pusherService;
    private SmsService $smsService;
    private CommissionService $commissionService;

    public function __construct()
    {
        $this->pusherService     = new BundlePortalOrderPusherService();
        $this->smsService        = new SmsService();
        $this->commissionService = new CommissionService();
    }

    public function syncOrderStatuses(): void
    {
        // Scope to Telecel and AT orders with a KT- reference (Bundle Portal)
        $orders = Order::whereIn('status', ['pending', 'processing'])
            ->whereNotNull('reference_id')
            ->where('reference_id', 'like', 'KT-%')
            ->where(function ($q) {
                $q->whereRaw('LOWER(network) LIKE ?', ['%telecel%'])
                  ->orWhereRaw('LOWER(network) LIKE ?', ['%at data%'])
                  ->orWhereRaw('LOWER(network) LIKE ?', ['%at (%'])
                  ->orWhereRaw('LOWER(network) LIKE ?', ['%airteltigo%'])
                  ->orWhereRaw('LOWER(network) LIKE ?', ['%ishare%']);
            })
            ->with('user')
            ->get();

        Log::info('Bundle Portal sync (Telecel/AT): found ' . $orders->count() . ' orders to check');

        foreach ($orders as $order) {
            try {
                $this->syncOrder($order);
            } catch (\Exception $e) {
                Log::error('Failed to sync Bundle Portal order status (Telecel/AT)', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    private function syncOrder(Order $order): void
    {
        $reference    = $order->reference_id;
        $responseData = $this->pusherService->checkOrderStatus($reference);

        if (!$responseData || !isset($responseData['success']) || $responseData['success'] !== true) {
            Log::warning('Bundle Portal status check returned no usable data (Telecel/AT)', [
                'order_id'  => $order->id,
                'reference' => $reference,
            ]);
            return;
        }

        $externalStatus = $responseData['data']['status'] ?? '';
        $newStatus      = $this->mapStatus($externalStatus);

        Log::info('Bundle Portal status check (Telecel/AT)', [
            'order_id'        => $order->id,
            'reference'       => $reference,
            'external_status' => $externalStatus,
            'mapped_status'   => $newStatus,
            'current_status'  => $order->status,
        ]);

        if (!$newStatus || $newStatus === $order->status) {
            return;
        }

        $oldStatus = $order->status;
        $order->update(['status' => $newStatus]);

        Log::info('Bundle Portal order status updated (Telecel/AT)', [
            'order_id'   => $order->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);

        if ($newStatus === 'completed') {
            if ($order->user && $order->user->phone) {
                try {
                    $this->smsService->sendSms(
                        $order->user->phone,
                        "Your {$order->network} order #{$order->id} to {$order->beneficiary_number} has been completed successfully. Thank you!"
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to send SMS for Bundle Portal order (Telecel/AT)', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $this->commissionService->makeCommissionAvailable($order);
        }

        if ($newStatus === 'cancelled') {
            $this->commissionService->reverseCommission($order);
        }
    }

    private function mapStatus(string $externalStatus): ?string
    {
        $map = [
            'processing' => 'processing',
            'pending'    => 'processing',
            'cached'     => 'processing',
            'completed'  => 'completed',
            'delivered'  => 'completed',
            'successful' => 'completed',
            'failed'     => 'cancelled',
            'cancelled'  => 'cancelled',
        ];

        return $map[strtolower($externalStatus)] ?? null;
    }
}
