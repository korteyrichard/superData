<?php

namespace App\Http\Controllers;

use App\Models\AgentShop;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Order;
use App\Models\Commission;
use App\Models\Transaction;
use App\Services\OrderPusherService;
use App\Services\CodeCraftOrderPusherService;
use App\Services\CodeCraftMtnOrderPusherService;
use App\Services\ProdataWorldOrderPusherService;
use App\Services\DataEasyOrderPusherService;
use App\Services\DataFlowOrderPusherService;
use App\Services\BundlePortalMtnOrderPusherService;
use App\Services\BundlePortalOrderPusherService;
use App\Models\Setting;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PublicShopController extends Controller
{
    public function __construct()
    {
        Log::info('PublicShopController instantiated');
    }
    public function show($username)
    {
        // Validate and sanitize the username input
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
            abort(404, 'Shop not found');
        }

        $shop = AgentShop::where('username', $username)
            ->where('is_active', true)
            ->with(['user', 'agentProducts.product'])
            ->first();

        if (!$shop) {
            // Check if the current authenticated user is a dealer without a shop
            if (auth()->check() && auth()->user()->role === 'dealer' && !auth()->user()->agentShop) {
                return redirect()->route('dealer.dashboard')
                    ->with('message', 'You need to create a shop first. Go to the dealer dashboard to set up your shop.');
            }
            
            abort(404, 'Shop not found');
        }

        $products = $shop->agentProducts->where('is_active', true)->filter(function ($agentProduct) {
            return $agentProduct->product->status === 'IN STOCK'
                && $agentProduct->agent_price >= $agentProduct->product->price;
        })->map(function ($agentProduct) {
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
        })->values();

        // No mashup packages available

        return Inertia::render('PublicShop', [
            'shop' => [
                'name' => $shop->name,
                'username' => $shop->username,
                'agent_name' => $shop->user->name,
                'color' => $shop->color,
                'whatsapp_contact' => $shop->whatsapp_contact
            ],
            'products' => $products,
            'auth' => [
                'user' => auth()->user()
            ],
            'settings' => [
                'how_to_track_orders_youtube_link' => Setting::get('how_to_track_orders_youtube_link')
            ]
        ]);
    }

    public function purchase(Request $request)
    {
        // Validate agent_username input to prevent SQL injection
        $validatedData = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'beneficiary_number' => 'required|string|size:10|regex:/^[0-9]{10}$/',
            'agent_username' => 'required|string|regex:/^[a-zA-Z0-9_-]+$/|exists:agent_shops,username',
            'customer_email' => 'required|email'
        ]);

        $shop = AgentShop::where('username', $validatedData['agent_username'])->first();
        
        if (!$shop) {
            return redirect()->back()->with('error', 'Shop not found');
        }
        
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
                'agent_username' => $validatedData['agent_username'], // Store the shop username
                'product_id' => $product->id,
                'quantity' => $validatedData['quantity'],
                'price' => $agentProduct->agent_price,
                'beneficiary_number' => $validatedData['beneficiary_number'],
                'customer_name' => $validatedData['customer_email'],
                'customer_phone' => $customerPhone,
                'total' => $total,
                'reference' => $reference
            ]
        ]);

        // Initialize Paystack payment
        $email = $validatedData['customer_email'];
        
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
                'customer_name' => $validatedData['customer_email'],
                'customer_phone' => $customerPhone,
                'agent_username' => $validatedData['agent_username'],
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
        
        // Add rate limiting to prevent rapid callback processing
        $cacheKey = "agent_order_callback_{$reference}";
        if (\Cache::has($cacheKey)) {
            return redirect()->route('home')->with('info', 'Order is being processed.');
        }
        \Cache::put($cacheKey, true, 60); // 1-minute lock

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . config('paystack.secret_key'),
            ])->get("https://api.paystack.co/transaction/verify/{$reference}");

            if ($response->successful() && $response->json('data.status') === 'success') {
                $orderData = session('pending_agent_order');
                
                if ($orderData && $orderData['reference'] === $reference) {
                    // Process order creation in a database transaction with proper locking
                    $result = DB::transaction(function () use ($orderData, $reference) {
                        // Check if order already exists to prevent duplicate processing
                        $existingOrder = Order::where('paystack_reference', $reference)
                            ->lockForUpdate()
                            ->first();
                        
                        if ($existingOrder) {
                            Log::info('Order already exists for reference', ['reference' => $reference]);
                            return ['success' => true, 'order_id' => $existingOrder->id, 'existing' => true];
                        }

                        // Get the shop where the order was made from using the stored username
                        // Validate the username from session data to ensure it's safe
                        $agentUsername = $orderData['agent_username'] ?? '';
                        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $agentUsername)) {
                            Log::error('Invalid agent username in session data', [
                                'agent_username' => $agentUsername,
                                'order_reference' => $reference
                            ]);
                            return ['success' => false, 'message' => 'Invalid shop identifier'];
                        }
                        
                        $shop = AgentShop::where('username', $agentUsername)
                            ->lockForUpdate()
                            ->first();
                        
                        if (!$shop) {
                            Log::error('Shop not found for commission calculation', [
                                'agent_username' => $agentUsername,
                                'order_reference' => $reference
                            ]);
                            return ['success' => false, 'message' => 'Shop not found'];
                        }
                        
                        // Validate and sanitize order data from session
                        $productId = is_numeric($orderData['product_id']) ? (int)$orderData['product_id'] : null;
                        $beneficiaryNumber = preg_match('/^[0-9]{10}$/', $orderData['beneficiary_number']) ? $orderData['beneficiary_number'] : null;
                        
                        if (!$productId || !$beneficiaryNumber) {
                            Log::error('Invalid order data in session', [
                                'product_id' => $orderData['product_id'] ?? 'null',
                                'beneficiary_number' => $orderData['beneficiary_number'] ?? 'null'
                            ]);
                            return ['success' => false, 'message' => 'Invalid order data'];
                        }
                        
                        // Create order record with proper agent_id for commission tracking
                        $order = Order::create([
                            'user_id' => $orderData['agent_id'], // Assign to agent whose shop the order was made from
                            'agent_id' => $orderData['agent_id'], // Set agent_id for commission calculation
                            'status' => 'processing',
                            'total' => $orderData['total'],
                            'beneficiary_number' => $beneficiaryNumber,
                            'network' => Product::find($productId)->network,
                            'customer_name' => $orderData['customer_name'],
                            'customer_phone' => $orderData['customer_phone'],
                            'paystack_reference' => $reference,
                            'customer_email' => $orderData['customer_name'] // This is actually email from the form
                        ]);

                        // Attach product to order with base price in pivot table
                        $basePrice = Product::find($productId)->price;
                        $order->products()->attach($productId, [
                            'quantity' => $orderData['quantity'],
                            'price' => $basePrice, // Store base price for commission calculation
                            'beneficiary_number' => $beneficiaryNumber
                        ]);

                        // Use CommissionService for consistent commission calculation within transaction
                        $order->load('agent.agentShop.agentProducts', 'products');
                        $commissionService = new \App\Services\CommissionService();
                        // Pass the shop information to the commission service
                        $commission = $commissionService->calculateAndCreateCommissionFromShop($order, $shop);

                        return ['success' => true, 'order_id' => $order->id, 'existing' => false];
                    });

                    \Cache::forget($cacheKey); // Release the lock
                    
                    if (!$result['success']) {
                        return redirect()->route('home')->with('error', $result['message'] ?? 'Order processing failed');
                    }

                    // Clear session after successful processing
                    session()->forget('pending_agent_order');

                    // Push order to external API (outside transaction)
                    if (!$result['existing']) {
                        $order = Order::find($result['order_id']);
                        try {
                            if ($this->isMtnOrder($order)) {
                                if (Setting::get('bundleportal_mtn_api_enabled', 'false') === 'true') {
                                    $orderPusher = new BundlePortalMtnOrderPusherService();
                                    $orderPusher->pushOrderToApi($order);
                                } elseif (Setting::get('codecraft_mtn_api_enabled', 'false') === 'true') {
                                    $orderPusher = new CodeCraftMtnOrderPusherService();
                                    $orderPusher->pushOrderToApi($order);
                                } elseif (Setting::get('dataflow_api_enabled', 'false') === 'true') {
                                    $orderPusher = new DataFlowOrderPusherService();
                                    $orderPusher->pushOrderToApi($order);
                                } elseif (Setting::get('dataeasy_api_enabled', 'false') === 'true') {
                                    $orderPusher = new DataEasyOrderPusherService();
                                    $orderPusher->pushOrderToApi($order);
                                } elseif (Setting::get('prodataworld_api_enabled', 'false') === 'true') {
                                    $orderPusher = new ProdataWorldOrderPusherService();
                                    $orderPusher->pushOrderToApi($order);
                                } else {
                                    $orderPusher = new OrderPusherService();
                                    $orderPusher->pushOrderToApi($order);
                                }
                            } else {
                                if (Setting::get('bundleportal_api_enabled', 'false') === 'true') {
                                    $orderPusher = new BundlePortalOrderPusherService();
                                    $orderPusher->pushOrderToApi($order);
                                } else {
                                    $orderPusher = new CodeCraftOrderPusherService();
                                    $orderPusher->pushOrderToApi($order);
                                }
                            }
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error('Failed to push agent shop order to external API', [
                                'order_id' => $order->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    return redirect()->route('agent.order.success', ['order' => $result['order_id']]);
                }
            }

        } catch (\Exception $e) {
            \Cache::forget($cacheKey);
            Log::error('Agent order callback error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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

    public function trackOrder(Request $request)
    {
        $validatedData = $request->validate([
            'beneficiary_number' => 'required|string|size:10|regex:/^[0-9]{10}$/',
            'paystack_reference' => 'required|string|min:10|max:100|regex:/^[a-zA-Z0-9_-]+$/'
        ]);

        // Check if reference starts with 'wallet' - reject wallet top-up references
        if (str_starts_with(strtolower($validatedData['paystack_reference']), 'wallet')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reference. Wallet top-up references cannot be used for order tracking.'
            ]);
        }

        // Allow references that start with 'agent_order' only
        $allowedPrefixes = ['agent_order'];
        $isValidReference = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with(strtolower($validatedData['paystack_reference']), $prefix)) {
                $isValidReference = true;
                break;
            }
        }
        
        if (!$isValidReference) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reference format. Please use a valid order reference.'
            ]);
        }

        try {
            // First, try to find existing order with indexed query
            $order = Order::where('beneficiary_number', $validatedData['beneficiary_number'])
                         ->where('paystack_reference', $validatedData['paystack_reference'])
                         ->select('id', 'status', 'total', 'beneficiary_number', 'network', 'customer_email', 'created_at')
                         ->with(['products:id,name,description,network'])
                         ->first();

            if ($order) {
                return response()->json([
                    'success' => true,
                    'order_found' => true,
                    'order' => [
                        'id' => $order->id,
                        'status' => $order->status,
                        'total' => $order->total,
                        'beneficiary_number' => $order->beneficiary_number,
                        'network' => $order->network,
                        'customer_email' => $order->customer_email,
                        'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                        'products' => $order->products->map(function($product) {
                            return [
                                'name' => $product->name,
                                'description' => $product->description,
                                'network' => $product->network
                            ];
                        })
                    ]
                ]);
            }

            // If order not found, verify with Paystack
            $paystackService = new PaystackService();
            $verification = $paystackService->verifyReference($validatedData['paystack_reference']);

            if (!$verification['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $verification['message']
                ]);
            }

            return response()->json([
                'success' => true,
                'order_found' => false,
                'can_create_order' => true,
                'payment_data' => $verification['data']
            ]);

        } catch (\Exception $e) {
            Log::error('Order tracking error', [
                'beneficiary_number' => $validatedData['beneficiary_number'],
                'reference' => $validatedData['paystack_reference'],
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while tracking your order. Please try again.'
            ], 500);
        }
    }

    public function createOrderFromReference(Request $request)
    {
        $validatedData = $request->validate([
            'beneficiary_number' => 'required|string|size:10|regex:/^[0-9]{10}$/',
            'paystack_reference' => 'required|string|min:10|max:100|regex:/^[a-zA-Z0-9_-]+$/',
            'product_id' => 'required|exists:products,id',
            'agent_username' => 'required|string|regex:/^[a-zA-Z0-9_-]+$/|exists:agent_shops,username'
        ]);

        // Check if reference starts with 'wallet' - reject wallet top-up references
        if (str_starts_with(strtolower($validatedData['paystack_reference']), 'wallet')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reference. Wallet top-up references cannot be used for order creation.'
            ]);
        }

        // Allow references that start with 'agent_order' only
        $allowedPrefixes = ['agent_order'];
        $isValidReference = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with(strtolower($validatedData['paystack_reference']), $prefix)) {
                $isValidReference = true;
                break;
            }
        }
        
        if (!$isValidReference) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reference format. Please use a valid order reference.'
            ]);
        }

        // Add rate limiting to prevent duplicate order creation attempts
        $cacheKey = "create_order_{$validatedData['paystack_reference']}";
        if (\Cache::has($cacheKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Order creation is already in progress. Please wait.'
            ]);
        }
        \Cache::put($cacheKey, true, 120); // 2-minute lock

        try {
            // Check if order already exists first
            $existingOrder = Order::where('paystack_reference', $validatedData['paystack_reference'])
                ->where('beneficiary_number', $validatedData['beneficiary_number'])
                ->first();
                
            if ($existingOrder) {
                \Cache::forget($cacheKey);
                return response()->json([
                    'success' => true,
                    'message' => 'Order already exists',
                    'order' => [
                        'id' => $existingOrder->id,
                        'status' => $existingOrder->status,
                        'total' => $existingOrder->total,
                        'beneficiary_number' => $existingOrder->beneficiary_number,
                        'network' => $existingOrder->network
                    ]
                ]);
            }

            // Verify payment again to ensure security
            $paystackService = new PaystackService();
            $verification = $paystackService->verifyReference($validatedData['paystack_reference']);

            if (!$verification['success']) {
                \Cache::forget($cacheKey);
                return response()->json([
                    'success' => false,
                    'message' => $verification['message']
                ]);
            }

            // Process order creation in a database transaction with proper locking
            $result = \DB::transaction(function () use ($validatedData, $verification) {
                // Lock shop and validate
                $shop = AgentShop::where('username', $validatedData['agent_username'])
                                ->where('is_active', true)
                                ->lockForUpdate()
                                ->first();
                if (!$shop) {
                    return ['success' => false, 'message' => 'Shop not found or inactive'];
                }

                // Lock product and validate
                $product = Product::where('id', $validatedData['product_id'])
                                 ->where('status', 'IN STOCK')
                                 ->lockForUpdate()
                                 ->first();
                if (!$product) {
                    return ['success' => false, 'message' => 'Product not found or out of stock'];
                }

                $agentProduct = $shop->agentProducts()
                                    ->where('product_id', $product->id)
                                    ->where('is_active', true)
                                    ->lockForUpdate()
                                    ->first();
                
                if (!$agentProduct) {
                    return ['success' => false, 'message' => 'Product not available in this shop'];
                }

                // Verify payment amount matches product price (allow 1 pesewa difference for rounding)
                $expectedAmount = $agentProduct->agent_price;
                $paidAmount = $verification['data']['amount'];
                
                if (abs($paidAmount - $expectedAmount) > 0.01) {
                    return [
                        'success' => false,
                        'message' => "Payment amount (₵{$paidAmount}) does not match product price (₵{$expectedAmount})"
                    ];
                }

                // Double-check for existing order within transaction
                $existingOrder = Order::where('paystack_reference', $validatedData['paystack_reference'])
                    ->lockForUpdate()
                    ->first();
                    
                if ($existingOrder) {
                    return [
                        'success' => true,
                        'message' => 'Order already exists',
                        'order_id' => $existingOrder->id,
                        'existing' => true
                    ];
                }

                // Create the order
                $order = Order::create([
                    'user_id' => $shop->user_id,
                    'agent_id' => $shop->user_id,
                    'status' => 'processing',
                    'total' => $expectedAmount,
                    'beneficiary_number' => $validatedData['beneficiary_number'],
                    'network' => $product->network,
                    'customer_name' => $verification['data']['email'],
                    'customer_phone' => $validatedData['beneficiary_number'],
                    'paystack_reference' => $validatedData['paystack_reference'],
                    'customer_email' => $verification['data']['email']
                ]);

                // Attach product to order with correct quantity
                Log::info('Attaching product to recovered order', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_quantity' => $product->quantity,
                    'order_id' => $order->id
                ]);
                
                // Extract numeric value from quantity (e.g., "2GB" -> 2)
                $numericQuantity = (int) filter_var($product->quantity, FILTER_SANITIZE_NUMBER_INT);
                if ($numericQuantity <= 0) {
                    $numericQuantity = 1; // Default fallback
                }
                
                Log::info('Using numeric quantity', [
                    'original_quantity' => $product->quantity,
                    'numeric_quantity' => $numericQuantity
                ]);
                
                $order->products()->attach($product->id, [
                    'quantity' => $numericQuantity,
                    'price' => $product->price,
                    'beneficiary_number' => $validatedData['beneficiary_number']
                ]);

                // Calculate commission within transaction
                $order->load('agent.agentShop.agentProducts', 'products');
                $commissionService = new \App\Services\CommissionService();
                $commission = $commissionService->calculateAndCreateCommissionFromShop($order, $shop);

                return [
                    'success' => true,
                    'order_id' => $order->id,
                    'existing' => false
                ];
            });

            \Cache::forget($cacheKey); // Release the lock

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ]);
            }

            // Push order to external API (outside transaction)
            if (!$result['existing']) {
                $order = Order::find($result['order_id']);
                try {
                    Log::info('Pushing recovered order to external API', [
                        'order_id' => $order->id,
                        'total' => $order->total,
                        'beneficiary' => $validatedData['beneficiary_number']
                    ]);
                    
                    if ($this->isMtnOrder($order)) {
                        // Check if CodeCraft MTN API is enabled first
                        if (Setting::get('codecraft_mtn_api_enabled', 'false') === 'true') {
                            $orderPusher = new CodeCraftMtnOrderPusherService();
                            $orderPusher->pushOrderToApi($order);
                        } elseif (Setting::get('dataflow_api_enabled', 'false') === 'true') {
                            $orderPusher = new DataFlowOrderPusherService();
                            $orderPusher->pushOrderToApi($order);
                        } elseif (Setting::get('dataeasy_api_enabled', 'false') === 'true') {
                            $orderPusher = new DataEasyOrderPusherService();
                            $orderPusher->pushOrderToApi($order);
                        } elseif (Setting::get('prodataworld_api_enabled', 'false') === 'true') {
                            $orderPusher = new ProdataWorldOrderPusherService();
                            $orderPusher->pushOrderToApi($order);
                        } else {
                            $orderPusher = new OrderPusherService();
                            $orderPusher->pushOrderToApi($order);
                        }
                    } else {
                        $orderPusher = new CodeCraftOrderPusherService();
                        $orderPusher->pushOrderToApi($order);
                    }
                    
                    Log::info('Successfully pushed recovered order to API', ['order_id' => $order->id]);
                } catch (\Exception $e) {
                    Log::error('Failed to push recovered order to external API', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage()
                    ]);
                    // Don't fail the order creation if API push fails
                }
            }

            $order = Order::find($result['order_id']);
            Log::info('Order created from Paystack reference', [
                'order_id' => $order->id,
                'reference' => $validatedData['paystack_reference'],
                'beneficiary' => $validatedData['beneficiary_number'],
                'amount' => $order->total
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'order' => [
                    'id' => $order->id,
                    'status' => $order->status,
                    'total' => $order->total,
                    'beneficiary_number' => $order->beneficiary_number,
                    'network' => $order->network
                ]
            ]);

        } catch (\Exception $e) {
            \Cache::forget($cacheKey);
            
            Log::error('Failed to create order from reference', [
                'reference' => $validatedData['paystack_reference'],
                'beneficiary_number' => $validatedData['beneficiary_number'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create order. Please contact support if this issue persists.'
            ], 500);
        }
    }

    private function isMtnOrder($order)
    {
        $network = strtolower($order->network ?? '');
        return stripos($network, 'mtn') !== false;
    }
}