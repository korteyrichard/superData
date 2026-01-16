<?php

namespace App\Http\Controllers;

use App\Models\AgentShop;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Order;
use App\Models\Commission;
use App\Models\Transaction;
use App\Services\OrderPusherService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublicShopController extends Controller
{
    public function show($username)
    {
        $shop = AgentShop::where('username', $username)
            ->where('is_active', true)
            ->with(['user', 'agentProducts.product'])
            ->first();

        if (!$shop) {
            abort(404, 'Shop not found');
        }

        $products = $shop->agentProducts->where('is_active', true)->map(function ($agentProduct) {
            return [
                'id' => $agentProduct->product->id,
                'name' => $agentProduct->product->name,
                'description' => $agentProduct->product->description,
                'network' => $agentProduct->product->network,
                'base_price' => $agentProduct->product->price,
                'agent_price' => $agentProduct->agent_price,
                'product_type' => $agentProduct->product->product_type,
                'status' => $agentProduct->product->status,
                'quantity' => $agentProduct->product->quantity
            ];
        });

        return Inertia::render('PublicShop', [
            'shop' => [
                'name' => $shop->name,
                'username' => $shop->username,
                'agent_name' => $shop->user->name
            ],
            'products' => $products,
            'auth' => [
                'user' => auth()->user()
            ]
        ]);
    }

    public function purchase(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'beneficiary_number' => 'required|string|size:10|regex:/^[0-9]{10}$/',
            'agent_username' => 'required|string|exists:agent_shops,username',
            'customer_email' => 'required|email'
        ]);

        $shop = AgentShop::where('username', $request->agent_username)->first();
        $product = Product::findOrFail($request->product_id);
        
        $agentProduct = $shop->agentProducts()->where('product_id', $product->id)->first();
        if (!$agentProduct) {
            return redirect()->back()->with('error', 'Product not available in this shop');
        }

        $total = $agentProduct->agent_price; // Don't multiply by quantity for data bundles
        $reference = 'agent_order_' . \Illuminate\Support\Str::random(16);
        $customerPhone = $request->customer_phone ?? $request->beneficiary_number;
        
        // Store order data in session for payment callback
        session([
            'pending_agent_order' => [
                'agent_id' => $shop->user_id,
                'agent_username' => $request->agent_username, // Store the shop username
                'product_id' => $product->id,
                'quantity' => $request->quantity,
                'price' => $agentProduct->agent_price,
                'beneficiary_number' => $request->beneficiary_number,
                'customer_name' => $request->customer_email,
                'customer_phone' => $customerPhone,
                'total' => $total,
                'reference' => $reference
            ]
        ]);

        // Initialize Paystack payment
        $email = $request->customer_email;
        
        \Illuminate\Support\Facades\Log::info('Initializing Paystack payment', [
            'email' => $email,
            'amount' => $total * 100,
            'reference' => $reference
        ]);
        
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . config('paystack.secret_key'),
            'Content-Type' => 'application/json',
        ])->post('https://api.paystack.co/transaction/initialize', [
            'email' => $email,
            'amount' => $total * 100, // Convert to kobo
            'callback_url' => route('agent.order.callback'),
            'reference' => $reference,
            'metadata' => [
                'customer_name' => $request->customer_email,
                'customer_phone' => $customerPhone,
                'agent_username' => $request->agent_username,
                'type' => 'agent_order'
            ]
        ]);

        if ($response->successful()) {
            return Inertia::location($response->json('data.authorization_url'));
        }

        // Log the error for debugging
        \Illuminate\Support\Facades\Log::error('Paystack initialization failed', [
            'response' => $response->json(),
            'status' => $response->status()
        ]);

        return redirect()->back()->with('error', 'Payment initialization failed: ' . $response->json('message', 'Unknown error'));
    }

    public function handleOrderCallback(Request $request)
    {
        $reference = $request->reference;
        
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . config('paystack.secret_key'),
        ])->get("https://api.paystack.co/transaction/verify/{$reference}");

        if ($response->successful() && $response->json('data.status') === 'success') {
            $orderData = session('pending_agent_order');
            
            if ($orderData && $orderData['reference'] === $reference) {
                // Get the shop where the order was made from using the stored username
                $shop = AgentShop::where('username', $orderData['agent_username'])->first();
                
                if (!$shop) {
                    Log::error('Shop not found for commission calculation', [
                        'agent_username' => $orderData['agent_username'],
                        'order_reference' => $reference
                    ]);
                    return redirect()->route('home')->with('error', 'Shop not found');
                }
                
                // Create order record with proper agent_id for commission tracking
                $order = Order::create([
                    'user_id' => $orderData['agent_id'], // Assign to agent whose shop the order was made from
                    'agent_id' => $orderData['agent_id'], // Set agent_id for commission calculation
                    'status' => 'processing',
                    'total' => $orderData['total'],
                    'beneficiary_number' => $orderData['beneficiary_number'],
                    'network' => Product::find($orderData['product_id'])->network,
                    'customer_name' => $orderData['customer_name'],
                    'customer_phone' => $orderData['customer_phone']
                ]);

                // Attach product to order with base price in pivot table
                $basePrice = Product::find($orderData['product_id'])->price;
                $order->products()->attach($orderData['product_id'], [
                    'quantity' => $orderData['quantity'],
                    'price' => $basePrice, // Store base price for commission calculation
                    'beneficiary_number' => $orderData['beneficiary_number']
                ]);

                // Use CommissionService for consistent commission calculation
                $order->load('agent.agentShop.agentProducts', 'products');
                $commissionService = new \App\Services\CommissionService();
                // Pass the shop information to the commission service
                $commission = $commissionService->calculateAndCreateCommissionFromShop($order, $shop);

                // Clear session
                session()->forget('pending_agent_order');

                // Push order to external API
                try {
                    $orderPusher = new OrderPusherService();
                    $orderPusher->pushOrderToApi($order);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to push agent shop order to external API', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage()
                    ]);
                }

                return redirect()->route('agent.order.success', ['order' => $order->id]);
            }
        }

        return redirect()->route('home')->with('error', 'Payment verification failed');
    }

    public function orderSuccess($orderId)
    {
        $order = Order::with('products')->findOrFail($orderId);
        
        return Inertia::render('OrderSuccess', [
            'order' => $order
        ]);
    }
}