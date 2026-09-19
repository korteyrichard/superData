<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handleOrderStatus(Request $request)
    {
        // Log all incoming webhook requests for debugging
        Log::info('WEBHOOK RECEIVED', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
            'ip' => $request->ip(),
            'timestamp' => now()
        ]);
        
        $payload = $request->getContent();
        $signature = $request->header('X-Webhook-Signature');
        $secret = env('WEBHOOK_SECRET', 'fd93ce342f0b1782bed029481943428f');
        
        // Log signature verification details
        Log::info('Webhook signature verification', [
            'received_signature' => $signature,
            'payload_length' => strlen($payload),
            'secret_configured' => !empty($secret)
        ]);
        
        // Verify webhook signature if provided
        if ($signature && env('WEBHOOK_REQUIRE_SIGNATURE', false)) {
            $expected = hash_hmac('sha256', $payload, $secret);
            
            if (!hash_equals($expected, $signature)) {
                Log::warning('Invalid webhook signature', [
                    'signature' => $signature, 
                    'expected' => $expected,
                    'payload' => $payload
                ]);
                return response()->json(['error' => 'Invalid signature'], 401);
            }
            Log::info('Webhook signature verified successfully');
        } elseif ($signature) {
            Log::info('Webhook signature provided but verification disabled');
        } else {
            Log::info('No webhook signature provided - proceeding without verification');
        }
        
        $data = json_decode($payload, true);
        
        if (!$data) {
            Log::error('Failed to decode webhook payload', ['payload' => $payload]);
            return response()->json(['error' => 'Invalid JSON payload'], 400);
        }
        
        Log::info('Webhook payload decoded', ['data' => $data]);
        
        // Handle different payload structures - be very flexible
        $order = null;
        $orderIdentifier = null;
        
        // Try multiple ways to find the order
        $searchMethods = [
            // Method 1: By reference_id
            ['field' => 'reference_id', 'value' => $data['reference_id'] ?? null],
            // Method 2: By order_id as reference_id
            ['field' => 'reference_id', 'value' => $data['order_id'] ?? null],
            // Method 3: By direct order ID
            ['field' => 'id', 'value' => $data['order_id'] ?? null],
            // Method 4: By any numeric identifier
            ['field' => 'id', 'value' => $data['id'] ?? null]
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
                'search_methods_tried' => $searchMethods,
                'webhook_data' => $data
            ]);
            return response()->json(['message' => 'Order not found'], 404);
        }
        
        // Determine the new status from payload - be flexible
        $newStatus = null;
        $statusFields = ['status', 'new_status', 'order_status', 'state'];
        
        foreach ($statusFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $newStatus = $data[$field];
                Log::info('Status found in field: ' . $field, ['status' => $newStatus]);
                break;
            }
        }
        
        if (!$newStatus) {
            Log::error('No status found in webhook payload', ['data' => $data]);
            return response()->json(['error' => 'No status provided'], 400);
        }
        
        // Validate status is allowed
        $allowedStatuses = ['pending', 'processing', 'completed', 'cancelled'];
        if (!in_array($newStatus, $allowedStatuses)) {
            Log::warning('Invalid status in webhook', [
                'received_status' => $newStatus,
                'allowed_statuses' => $allowedStatuses
            ]);
            return response()->json(['error' => 'Invalid status: ' . $newStatus], 400);
        }
        
        $oldStatus = $order->status;
        
        // Update order status
        try {
            $order->update([
                'status' => $newStatus
            ]);
            
            Log::info('ORDER STATUS UPDATED VIA WEBHOOK', [
                'order_id' => $order->id,
                'reference_id' => $order->reference_id,
                'identifier_used' => $orderIdentifier,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'webhook_data' => $data,
                'updated_at' => $order->updated_at
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully',
                'order_id' => $order->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update order status via webhook', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'webhook_data' => $data
            ]);
            
            return response()->json([
                'error' => 'Failed to update order status',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}