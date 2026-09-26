<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class Mtn3BundlePortalOrderStatusSyncService
{
    private Mtn3BundlePortalOrderPusherService $pusherService;
    private SmsService $smsService;
    private CommissionService $commissionService;

    public function __construct()
    {
        $this->pusherService = new Mtn3BundlePortalOrderPusherService();
        $this->smsService = new SmsService();
        $this->commissionService = new CommissionService();
    }

    public function syncOrderStatuses(): void
    {
        Log::warning('Bundle Portal MTN3 v2 no longer supports polling; status updates must arrive via webhook.', [
            'reason' => 'polling_disabled',
            'endpoint' => $this->pusherService->getBaseUrl(),
        ]);
    }

    private function syncOrder(Order $order): void
    {
        $reference = (string) $order->reference_id;
        $responseData = $this->pusherService->checkOrderStatus($reference);

        if (!$responseData || ($responseData['success'] ?? false) !== true) {
            Log::warning('Bundle Portal MTN3 status check returned no usable data', [
                'order_id' => $order->id,
                'reference' => $reference,
            ]);
            return;
        }

        $externalStatus = $responseData['data']['status'] ?? '';
        $newStatus = $this->mapStatus($externalStatus);

        Log::info('Bundle Portal MTN3 status check', [
            'order_id' => $order->id,
            'reference' => $reference,
            'external_status' => $externalStatus,
            'mapped_status' => $newStatus,
            'current_status' => $order->status,
        ]);

        if (!$newStatus || $newStatus === $order->status) {
            return;
        }

        $oldStatus = $order->status;
        $order->update(['status' => $newStatus]);

        Log::info('Bundle Portal MTN3 order status updated', [
            'order_id' => $order->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);

        if ($newStatus === 'completed') {
            if ($order->user && $order->user->phone) {
                try {
                    $this->smsService->sendSms(
                        $order->user->phone,
                        "Your MTN Instant order #{$order->id} to {$order->beneficiary_number} has been completed successfully. Thank you!"
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to send SMS for Bundle Portal MTN3 order', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
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
        return [
            'processing' => 'processing',
            'pending' => 'processing',
            'cached' => 'processing',
            'completed' => 'completed',
            'delivered' => 'completed',
            'successful' => 'completed',
            'failed' => 'cancelled',
            'cancelled' => 'cancelled',
        ][strtolower($externalStatus)] ?? null;
    }
}
