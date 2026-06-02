<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\CommissionService;
use App\Services\CodeCraftOrderPusherService;
use App\Services\CodeCraftOrderStatusSyncService;
use App\Services\CodeCraftMtnOrderStatusSyncService;
use App\Services\ProdataWorldOrderPusherService;
use App\Services\DataEasyOrderPusherService;
use App\Services\DataEasyOrderStatusSyncService;
use App\Services\DataFlowOrderStatusSyncService;

class OrderStatusSyncService
{
    private $jaybartApiKey;
    private $moolreSmsService;
    private $commissionService;
    private $codeCraftService;
    private $codeCraftSyncService;
    private $codeCraftMtnSyncService;
    private $prodataWorldService;
    private $dataEasyService;
    private $dataEasySyncService;
    private $dataFlowSyncService;

    public function __construct()
    {
        $this->jaybartApiKey = env('ORDER_PUSHER_API_KEY', '75dc87ab33239934578afbf81a9dee777d591e4f');
        $this->moolreSmsService = new SmsService();
        $this->commissionService = new CommissionService();
        $this->codeCraftService = new CodeCraftOrderPusherService();
        $this->codeCraftSyncService = new CodeCraftOrderStatusSyncService();
        $this->codeCraftMtnSyncService = new CodeCraftMtnOrderStatusSyncService();
        $this->prodataWorldService = new ProdataWorldOrderPusherService();
        $this->dataEasyService = new DataEasyOrderPusherService();
        $this->dataEasySyncService = new DataEasyOrderStatusSyncService();
        $this->dataFlowSyncService = new DataFlowOrderStatusSyncService();
    }

    public function syncOrderStatuses()
    {
        $processingOrders = Order::whereIn('status', ['pending', 'processing'])->with('user')->get();
        
        foreach ($processingOrders as $order) {
            try {
                if (Setting::get('dataeasy_api_enabled', 'false') === 'true' && $this->isMtnOrder($order)) {
                    $this->syncDataEasyOrderStatus($order);
                } elseif (Setting::get('prodataworld_api_enabled', 'false') === 'true' && $this->isProdataWorldOrder($order)) {
                    $this->syncProdataWorldOrderStatus($order);
                } elseif ($this->isCodeCraftOrder($order)) {
                    $this->syncCodeCraftOrderStatus($order);
                } else {
                    $this->syncJaybartOrderStatus($order);
                }
            } catch (\Exception $e) {
                Log::error('Failed to sync order status', ['orderId' => $order->id, 'error' => $e->getMessage()]);
            }
        }
        
        // Also run the dedicated CodeCraft sync service
        try {
            $this->codeCraftSyncService->syncOrderStatuses();
        } catch (\Exception $e) {
            Log::error('Failed to run CodeCraft sync service', ['error' => $e->getMessage()]);
        }
        
        // Also run the dedicated DataEasy sync service
        try {
            $this->dataEasySyncService->syncOrderStatuses();
        } catch (\Exception $e) {
            Log::error('Failed to run DataEasy sync service', ['error' => $e->getMessage()]);
        }
        
        // Also run the dedicated CodeCraft MTN sync service
        try {
            $this->codeCraftMtnSyncService->syncOrderStatuses();
        } catch (\Exception $e) {
            Log::error('Failed to run CodeCraft MTN sync service', ['error' => $e->getMessage()]);
        }
        
        // Also run the dedicated DataFlow sync service
        try {
            $this->dataFlowSyncService->syncOrderStatuses();
        } catch (\Exception $e) {
            Log::error('Failed to run DataFlow sync service', ['error' => $e->getMessage()]);
        }
    }

    private function syncJaybartOrderStatus($order)
    {
        $referenceId = $this->extractReferenceId($order);
        
        Log::info('Jaybart sync attempt', [
            'order_id' => $order->id,
            'reference_id' => $referenceId,
            'order_network' => $order->network,
            'order_status' => $order->status
        ]);
        
        if (!$referenceId) {
            Log::warning('No reference ID found for Jaybart order', ['orderId' => $order->id]);
            return;
        }

        try {
            Log::info('Making Jaybart API call', [
                'order_id' => $order->id,
                'transaction_id' => $referenceId,
                'api_endpoint' => 'https://agent.jaybartservices.com/api/v1/fetch-other-network-transaction'
            ]);
            
            $response = Http::withHeaders([
                'x-api-key' => $this->jaybartApiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->timeout(20)->post('https://agent.jaybartservices.com/api/v1/fetch-other-network-transaction', [
                'transaction_id' => $referenceId
            ]);

            Log::info('Jaybart API response received', [
                'order_id' => $order->id,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'is_successful' => $response->successful()
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $externalStatus = '';
                if (isset($data['order_items']) && is_array($data['order_items']) && count($data['order_items']) > 0) {
                    $externalStatus = $data['order_items'][0]['status'] ?? '';
                }
                $newStatus = $this->mapJaybartStatus($externalStatus);
                
                Log::info('Jaybart status mapping', [
                    'order_id' => $order->id,
                    'external_status' => $externalStatus,
                    'mapped_status' => $newStatus,
                    'current_order_status' => $order->status
                ]);
                
                if ($newStatus && $newStatus !== $order->status) {
                    $oldStatus = $order->status;
                    $updateResult = $order->update(['status' => $newStatus]);
                    Log::info('Jaybart order status updated', [
                        'orderId' => $order->id, 
                        'oldStatus' => $oldStatus, 
                        'newStatus' => $newStatus,
                        'update_successful' => $updateResult
                    ]);
                    
                    // Send SMS notification if order is completed
                    if ($newStatus === 'completed' && $order->user && $order->user->phone) {
                        try {
                            $message = "Your order #{$order->id} for {$order->network} data has been completed successfully. Thank you for using DataFraternity!";
                            $smsResult = $this->moolreSmsService->sendSms($order->user->phone, $message);
                            Log::info('SMS notification sent for completed order', [
                                'order_id' => $order->id,
                                'phone' => $order->user->phone,
                                'sms_success' => $smsResult
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Failed to send SMS notification', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                        }
                        
                        // Make commission available when order is completed
                        $this->commissionService->makeCommissionAvailable($order);
                    }
                    
                    // Reverse commission if order is cancelled
                    if ($newStatus === 'cancelled') {
                        $this->commissionService->reverseCommission($order);
                    }
                } else {
                    Log::info('Jaybart order status unchanged', [
                        'order_id' => $order->id,
                        'current_status' => $order->status,
                        'external_status' => $externalStatus,
                        'mapped_status' => $newStatus
                    ]);
                }
            } else {
                Log::warning('Jaybart API call unsuccessful', [
                    'order_id' => $order->id,
                    'status_code' => $response->status(),
                    'response' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Jaybart status check failed', ['orderId' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function extractReferenceId($order)
    {
        return $order->reference_id;
    }

    private function mapJaybartStatus($externalStatus)
    {
        Log::info('Jaybart Status mapping debug', [
            'input_status' => $externalStatus,
            'input_type' => gettype($externalStatus),
            'input_lowercased' => strtolower($externalStatus)
        ]);
        
        $statusMap = [
            'successful' => 'completed',
            'completed' => 'completed',
            'delivered' => 'completed',
            'placed' => 'processing',
            'processing' => 'processing',
            'pending' => 'processing',
            'failed' => 'cancelled',
            'cancelled' => 'cancelled'
        ];

        $lowercaseStatus = strtolower($externalStatus);
        $mappedStatus = $statusMap[$lowercaseStatus] ?? null;
        
        Log::info('Jaybart Status mapping result', [
            'original_status' => $externalStatus,
            'lowercase_status' => $lowercaseStatus,
            'mapped_status' => $mappedStatus,
            'available_mappings' => array_keys($statusMap)
        ]);

        return $mappedStatus;
    }

    private function isCodeCraftOrder($order)
    {
        $network = strtolower($order->network ?? '');
        return in_array($network, ['telecel', 'at data', 'at (big packages)']);
    }

    private function isProdataWorldOrder($order)
    {
        $network = strtolower($order->network ?? '');
        return stripos($network, 'mtn') !== false;
    }

    private function syncProdataWorldOrderStatus($order)
    {
        $referenceId = $this->extractReferenceId($order);
        
        Log::info('ProdataWorld sync attempt', [
            'order_id' => $order->id,
            'reference_id' => $referenceId,
            'order_network' => $order->network,
            'order_status' => $order->status
        ]);
        
        if (!$referenceId) {
            Log::warning('No reference ID found for ProdataWorld order', ['orderId' => $order->id]);
            return;
        }

        try {
            $responseData = $this->prodataWorldService->checkOrderStatus($referenceId);
            
            if ($responseData && isset($responseData['data'])) {
                $externalStatus = $responseData['data']['status'] ?? '';
                $newStatus = $this->mapProdataWorldStatus($externalStatus);
                
                Log::info('ProdataWorld status mapping', [
                    'order_id' => $order->id,
                    'external_status' => $externalStatus,
                    'mapped_status' => $newStatus,
                    'current_order_status' => $order->status
                ]);
                
                if ($newStatus && $newStatus !== $order->status) {
                    $oldStatus = $order->status;
                    $updateResult = $order->update(['status' => $newStatus]);
                    Log::info('ProdataWorld order status updated', [
                        'orderId' => $order->id, 
                        'oldStatus' => $oldStatus, 
                        'newStatus' => $newStatus,
                        'update_successful' => $updateResult
                    ]);
                    
                    // Send SMS notification if order is completed
                    if ($newStatus === 'completed' && $order->user && $order->user->phone) {
                        try {
                            $message = "Your order #{$order->id} for {$order->network} data has been completed successfully. Thank you for using DataFraternity!";
                            $smsResult = $this->moolreSmsService->sendSms($order->user->phone, $message);
                            Log::info('SMS notification sent for completed order', [
                                'order_id' => $order->id,
                                'phone' => $order->user->phone,
                                'sms_success' => $smsResult
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Failed to send SMS notification', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                        }
                        
                        // Make commission available when order is completed
                        $this->commissionService->makeCommissionAvailable($order);
                    }
                    
                    // Reverse commission if order is cancelled
                    if ($newStatus === 'cancelled') {
                        $this->commissionService->reverseCommission($order);
                    }
                }
            } else {
                Log::warning('ProdataWorld API returned no data', ['order_id' => $order->id]);
            }
        } catch (\Exception $e) {
            Log::error('ProdataWorld status check failed', ['orderId' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function syncCodeCraftOrderStatus($order)
    {
        $referenceId = $this->extractReferenceId($order);
        
        Log::info('CodeCraft sync attempt', [
            'order_id' => $order->id,
            'reference_id' => $referenceId,
            'order_network' => $order->network,
            'order_status' => $order->status
        ]);
        
        if (!$referenceId) {
            Log::warning('No reference ID found for CodeCraft order', ['orderId' => $order->id]);
            return;
        }

        try {
            $isBigTime = strtolower($order->network) === 'at (big packages)';
            $responseData = $this->codeCraftService->checkOrderStatus($referenceId, $isBigTime);
            
            if ($responseData) {
                $externalStatus = $responseData['status'] ?? '';
                $newStatus = $this->mapCodeCraftStatus($externalStatus);
                
                Log::info('CodeCraft status mapping', [
                    'order_id' => $order->id,
                    'external_status' => $externalStatus,
                    'mapped_status' => $newStatus,
                    'current_order_status' => $order->status
                ]);
                
                if ($newStatus && $newStatus !== $order->status) {
                    $oldStatus = $order->status;
                    $updateResult = $order->update(['status' => $newStatus]);
                    Log::info('CodeCraft order status updated', [
                        'orderId' => $order->id, 
                        'oldStatus' => $oldStatus, 
                        'newStatus' => $newStatus,
                        'update_successful' => $updateResult
                    ]);
                    
                    // Send SMS notification if order is completed
                    if ($newStatus === 'completed' && $order->user && $order->user->phone) {
                        try {
                            $message = "Your order #{$order->id} for {$order->network} data has been completed successfully. Thank you for using DataFraternity!";
                            $smsResult = $this->moolreSmsService->sendSms($order->user->phone, $message);
                            Log::info('SMS notification sent for completed order', [
                                'order_id' => $order->id,
                                'phone' => $order->user->phone,
                                'sms_success' => $smsResult
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Failed to send SMS notification', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                        }
                        
                        // Make commission available when order is completed
                        $this->commissionService->makeCommissionAvailable($order);
                    }
                    
                    // Reverse commission if order is cancelled
                    if ($newStatus === 'cancelled') {
                        $this->commissionService->reverseCommission($order);
                    }
                }
            } else {
                Log::warning('CodeCraft API returned no data', ['order_id' => $order->id]);
            }
        } catch (\Exception $e) {
            Log::error('CodeCraft status check failed', ['orderId' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function mapCodeCraftStatus($externalStatus)
    {
        $statusMap = [
            'successful' => 'completed',
            'completed' => 'completed',
            'delivered' => 'completed',
            'processing' => 'processing',
            'pending' => 'processing',
            'failed' => 'cancelled',
            'cancelled' => 'cancelled'
        ];

        $lowercaseStatus = strtolower($externalStatus);
        return $statusMap[$lowercaseStatus] ?? null;
    }

    private function mapProdataWorldStatus($externalStatus)
    {
        $statusMap = [
            'completed' => 'completed',
            'success' => 'completed',
            'successful' => 'completed',
            'processing' => 'processing',
            'pending' => 'processing',
            'failed' => 'cancelled',
            'cancelled' => 'cancelled'
        ];

        $lowercaseStatus = strtolower($externalStatus);
        return $statusMap[$lowercaseStatus] ?? null;
    }
    private function isMtnOrder($order)
    {
        $network = strtolower($order->network ?? '');
        return stripos($network, 'mtn') !== false;
    }

    private function syncDataEasyOrderStatus($order)
    {
        $referenceId = $this->extractReferenceId($order);
        
        Log::info('DataEasy sync attempt', [
            'order_id' => $order->id,
            'reference_id' => $referenceId,
            'order_network' => $order->network,
            'order_status' => $order->status
        ]);
        
        if (!$referenceId) {
            Log::warning('No reference ID found for DataEasy order', ['orderId' => $order->id]);
            return;
        }

        // Check if this referenceId looks like a DataEasy UUID (e.g. 8-4-4-4-12 chars)
        if (strlen($referenceId) < 20 && is_numeric($referenceId)) {
            Log::info('Skipping non-DataEasy ID in DataEasy sync', ['order_id' => $order->id, 'reference_id' => $referenceId]);
            return;
        }

        try {
            $responseData = $this->dataEasyService->checkOrderStatus($referenceId);
            
            if ($responseData && isset($responseData['success']) && $responseData['success'] === true && isset($responseData['order'])) {
                $orderData = $responseData['order'];
                $externalStatus = $orderData['deliveryStatus'] ?? '';
                $newStatus = $this->mapDataEasyStatus($externalStatus);
                
                Log::info('DataEasy status mapping', [
                    'order_id' => $order->id,
                    'external_status' => $externalStatus,
                    'mapped_status' => $newStatus,
                    'current_order_status' => $order->status
                ]);
                
                if ($newStatus && $newStatus !== $order->status) {
                    $oldStatus = $order->status;
                    $updateResult = $order->update(['status' => $newStatus]);
                    Log::info('DataEasy order status updated', [
                        'orderId' => $order->id, 
                        'oldStatus' => $oldStatus, 
                        'newStatus' => $newStatus,
                        'update_successful' => $updateResult
                    ]);
                    
                    // Send SMS notification if order is completed
                    if ($newStatus === 'completed' && $order->user && $order->user->phone) {
                        try {
                            $message = "Your order #{$order->id} to {$order->beneficiary_number} has been completed successfully. Thank you for using DF-Ghana!";
                            $smsResult = $this->moolreSmsService->sendSms($order->user->phone, $message);
                            Log::info('SMS notification sent for completed order', [
                                'order_id' => $order->id,
                                'phone' => $order->user->phone,
                                'sms_success' => $smsResult
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Failed to send SMS notification', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                        }
                        
                        // Make commission available when order is completed
                        $this->commissionService->makeCommissionAvailable($order);
                    }
                    
                    // Reverse commission if order is cancelled
                    if ($newStatus === 'cancelled') {
                        $this->commissionService->reverseCommission($order);
                    }
                }
            } else {
                Log::warning('DataEasy API returned no data', ['order_id' => $order->id]);
            }
        } catch (\Exception $e) {
            Log::error('DataEasy status check failed', ['orderId' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function mapDataEasyStatus($externalStatus)
    {
        $statusMap = [
            'Pending' => 'processing',
            'Processing' => 'processing',
            'Delivered' => 'completed',
            'Failed' => 'cancelled',
        ];

        return $statusMap[$externalStatus] ?? null;
    }
}