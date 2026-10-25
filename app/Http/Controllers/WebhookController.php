<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\BundlePortalOrderStatusSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handleOrderStatus(Request $request, BundlePortalOrderStatusSyncService $statusSync)
    {
        $payload = $request->getContent();
        $signature = $request->header('X-BundlePortal-Signature', $request->header('X-Webhook-Signature'));
        $secret = config('services.bundleportal.webhook_secret', '');

        Log::info('WEBHOOK RECEIVED', [
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'timestamp' => now(),
            'signature_present' => !empty($signature),
            'secret_present' => !empty($secret),
        ]);

        if (empty($secret)) {
            Log::warning('Bundle Portal webhook secret missing; rejecting request.');
            return response()->json(['error' => 'Webhook secret not configured'], 401);
        }

        if (!empty($signature)) {
            $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

            if (!hash_equals($expected, $signature)) {
                Log::warning('Invalid Bundle Portal webhook signature', ['ip' => $request->ip()]);
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        } else {
            Log::warning('Bundle Portal webhook signature missing', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Missing signature'], 401);
        }
        
        $data = json_decode($payload, true);
        
        if (!$data) {
            Log::warning('Failed to decode Bundle Portal webhook JSON', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Invalid JSON payload'], 400);
        }
        
        $details = isset($data['data']) && is_array($data['data'])
            ? array_merge($data['data'], $data)
            : $data;

        $order = null;
        $orderIdentifier = null;

        $searchMethods = [
            ['field' => 'reference_id', 'value' => $details['reference'] ?? null],
            ['field' => 'reference_id', 'value' => $details['order_id'] ?? null],
            ['field' => 'reference_id', 'value' => $details['reference_id'] ?? null],
            ['field' => 'reference_id', 'value' => $details['id'] ?? null],
            ['field' => 'id', 'value' => $details['order_id'] ?? null],
            ['field' => 'id', 'value' => $details['reference'] ?? null],
            ['field' => 'id', 'value' => $details['id'] ?? null],
        ];
        
        foreach ($searchMethods as $method) {
            if ($method['value']) {
                if ($method['field'] === 'id') {
                    $order = Order::find($method['value']);
                } else {
                    $order = Order::where($method['field'], $method['value'])->first();
                }
                
                if ($order) {
                    $orderIdentifier = $method['field'] . ':' . $method['value'];
                    Log::info('Order found', [
                        'method' => $method,
                        'order_id' => $order->id,
                        'current_status' => $order->status
                    ]);
                    break;
                }
            }
        }
        
        if (!$order) {
            Log::warning('Order not found for webhook', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Order not found'], 404);
        }
        
        $eventStatuses = [
            'order.completed' => 'completed',
            'order.failed' => 'failed',
            'order.cancelled' => 'cancelled',
            'order.refunded' => 'refunded',
        ];
        $event = strtolower((string) ($details['event'] ?? ''));
        $newStatus = $eventStatuses[$event] ?? null;
        $statusFields = ['status', 'new_status', 'order_status', 'state'];
        
        foreach ($newStatus ? [] : $statusFields as $field) {
            if (isset($details[$field]) && !empty($details[$field])) {
                $newStatus = $details[$field];
                break;
            }
        }
        
        if (!$newStatus) {
            Log::warning('No supported status found in Bundle Portal webhook', ['event' => $event ?: null]);
            return response()->json(['error' => 'No status provided'], 400);
        }

        $newStatus = strtolower((string) $newStatus);
        if (!in_array($newStatus, ['pending', 'processing', 'completed', 'failed', 'cancelled', 'refunded'], true)) {
            Log::warning('Invalid status in webhook', [
                'received_status' => $newStatus,
            ]);
            return response()->json(['error' => 'Invalid status: ' . $newStatus], 400);
        }

        $oldStatus = $order->status;

        try {
            $internalStatus = $statusSync->applyWebhookStatus($order, $newStatus);

            if (!$internalStatus) {
                return response()->json(['error' => 'Unsupported status'], 400);
            }
            
            Log::info('ORDER STATUS UPDATED VIA WEBHOOK', [
                'order_id' => $order->id,
                'reference_id' => $order->reference_id,
                'identifier_used' => $orderIdentifier,
                'old_status' => $oldStatus,
                'provider_status' => $newStatus,
                'new_status' => $internalStatus,
                'updated_at' => $order->updated_at
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully',
                'order_id' => $order->id,
                'old_status' => $oldStatus,
                'new_status' => $internalStatus,
                'provider_status' => $newStatus,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update order status via webhook', [
                'order_id' => $order->id,
                'exception' => get_class($e),
            ]);
            
            return response()->json([
                'error' => 'Failed to update order status',
            ], 500);
        }
    }
}
