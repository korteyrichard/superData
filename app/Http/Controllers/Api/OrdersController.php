<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\OrderPusherService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrdersController extends Controller
{
    use ApiResponse;
    public function createOrder(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|string|max:10',
            'beneficiary_number' => 'required|string|regex:/^[0-9+\-\s]+$/|max:20',
        ]);

        $user = $request->user();
        
        if (!in_array($user->role, ['agent', 'admin'])) {
            return $this->errorResponse('Only agents and admins can create orders via API', 403);
        }
        
        $product = Product::where('id', $request->product_id)
            ->where('status', 'IN STOCK')
            ->where('product_type', 'agent_product')
            ->first();

        if (!$product) {
            return $this->errorResponse('Product not found, out of stock, or not available for agents', 404);
        }

        // Validate that the product quantity matches the requested quantity
        if ($product->quantity !== $request->quantity) {
            return $this->errorResponse('Product quantity does not match. Available: ' . $product->quantity, 400);
        }

        $totalPrice = $product->price;

        // Check if user has enough wallet balance
        if ($user->wallet_balance < $totalPrice) {
            return $this->errorResponse('Insufficient wallet balance', 400, [
                'required_amount' => $totalPrice,
                'current_balance' => $user->wallet_balance
            ]);
        }

        DB::beginTransaction();
        try {
            // Deduct wallet balance
            $user->wallet_balance = (float) bcsub((string) $user->wallet_balance, (string) $totalPrice, 2);
            $user->save();

            // Create the order
            $order = Order::create([
                'user_id' => $user->id,
                'status' => 'processing',
                'total' => $totalPrice,
                'beneficiary_number' => $request->beneficiary_number,
                'network' => $product->network,
            ]);

            // Attach product to the order
            $order->products()->attach($product->id, [
                'quantity' => 1,
                'price' => $product->price,
                'beneficiary_number' => $request->beneficiary_number,
            ]);

            // Create transaction record
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'amount' => $totalPrice,
                'status' => 'completed',
                'type' => 'order',
                'description' => 'API Order placed for ' . $product->network . ' - ' . $product->name,
                'reference' => 'API-' . $order->id . '-' . time(),
            ]);

            DB::commit();

            // Push order to external API
            try {
                $orderPusher = new OrderPusherService();
                $orderPusher->pushOrderToApi($order);
            } catch (\Exception $e) {
                Log::error('Failed to push API order to external service', ['error' => $e->getMessage()]);
            }

            // Load order with relationships for response
            $order->load(['products', 'transactions']);

            return $this->successResponse([
                'order' => $order,
                'transaction' => $transaction,
            ], 'Order created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('API order creation failed', ['error' => $e->getMessage()]);
            
            return $this->errorResponse('Order creation failed: ' . $e->getMessage(), 500);
        }
    }

    public function getOrders(Request $request)
    {
        $user = $request->user();
        
        $orders = Order::with(['products' => function($query) {
            $query->withPivot('quantity', 'price', 'beneficiary_number');
        }, 'transactions'])
        ->where('user_id', $user->id)
        ->latest()
        ->paginate(20);

        return $this->successResponse($orders);
    }

    public function getOrder(Request $request, $id)
    {
        $user = $request->user();
        
        $order = Order::with(['products' => function($query) {
            $query->withPivot('quantity', 'price', 'beneficiary_number');
        }, 'transactions'])
        ->where('user_id', $user->id)
        ->findOrFail($id);

        return $this->successResponse($order);
    }

    public function getProducts(Request $request)
    {
        $user = $request->user();
        
        if (!in_array($user->role, ['agent', 'admin'])) {
            return $this->errorResponse('Only agents and admins can access products via API', 403);
        }
        
        $products = Product::where('status', 'IN STOCK')
            ->where('product_type', 'agent_product')
            ->select('id', 'name', 'price', 'network', 'product_type', 'description', 'quantity')
            ->get();

        return $this->successResponse($products);
    }
}